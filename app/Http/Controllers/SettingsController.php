<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Integration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\File; // Necessário para ler logs
use Illuminate\Support\Str;

use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * Exibe a tela de Logs do Sistema.
     */
    public function logs()
    {
        return redirect()->route('hub.monitor');
    }

    public function integrations()
    {
        $company = Auth::user()->company;
        
        // Configurações de chaves por plataforma — prioriza a linha "de
        // config" (sem seller_id/conta ainda) na keyBy, senão plataformas com
        // mais de uma conta conectada (ex.: Google Ads com múltiplos
        // Customer ID) podiam fazer o keyBy pegar uma conta ao acaso em vez
        // da linha com as chaves.
        $integrations = $company->integrations()
            ->orderByRaw('seller_id IS NULL DESC')
            ->orderByRaw('app_id IS NULL ASC')
            ->get()
            ->keyBy('platform');

        // Contas individuais (credentials) conectadas
        $credentials = Integration::where('company_id', $company->id)
            ->whereNotNull('access_token')
            ->get();

        return Inertia::render('Settings/Integrations', [
            'integrations' => $integrations,
            'credentials' => $credentials,
            'company' => $company
        ]);
    }

    public function updateKeys(Request $request, $platform)
    {
        $request->validate([
            'app_id' => 'required',
            'client_secret' => 'required',
        ]);

        $query = Integration::where('company_id', Auth::user()->company_id)
            ->where('platform', $platform);

        if ($query->exists()) {
            $query->update([
                'app_id' => $request->app_id,
                'client_secret' => $request->client_secret,
            ]);
        } else {
            Integration::create([
                'company_id' => Auth::user()->company_id,
                'platform' => $platform,
                'app_id' => $request->app_id,
                'client_secret' => $request->client_secret,
                'status' => 'pending_auth'
            ]);
        }

        return redirect()->back()->with('success', 'Credenciais salvas com sucesso! Agora clique em "Autorizar".');
    }

    public function redirectToMeli()
    {
        // Encontra qualquer integração que tenha as chaves configuradas para esta empresa
        $integration = Integration::where('company_id', Auth::user()->company_id)
            ->where('platform', 'mercadolibre')
            ->orderByRaw('app_id IS NOT NULL DESC')
            ->first();

        if (!$integration || !$integration->app_id || !$integration->client_secret) {
            return redirect()->route('settings.integrations')->with('error', 'Por favor, preencha e salve o App ID e o Client Secret antes de autorizar.');
        }

        $appId = $integration->app_id;
        $redirectUri = route('ml.callback');

        // PKCE
        $codeVerifier = Str::random(128);
        $codeChallenge = strtr(rtrim(base64_encode(hash('sha256', $codeVerifier, true)), '='), '+/', '-_');
        session(['meli_code_verifier' => $codeVerifier]);

        // Passa o ID do registro que tem as chaves (configuração)
        $state = $integration->id;

        $url = "https://auth.mercadolivre.com.br/authorization?response_type=code&client_id={$appId}&redirect_uri=" . urlencode($redirectUri) . "&state={$state}&code_challenge={$codeChallenge}&code_challenge_method=S256";

        return Inertia::location($url);
    }

    public function handleMeliCallback(Request $request)
    {
        $code = $request->code;
        $state = $request->state; // ID of the integration (template/config record)

        if (!$code) {
            return redirect()->route('marketplaces.accounts.index')->with('error', 'Autorização cancelada.');
        }

        $config = Integration::findOrFail($state);

        $codeVerifier = session('meli_code_verifier');

        $response = Http::asForm()->post('https://api.mercadolibre.com/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $config->app_id,
            'client_secret' => $config->client_secret,
            'code' => $code,
            'redirect_uri' => route('ml.callback'),
            'code_verifier' => $codeVerifier,
        ]);

        if ($response->successful()) {
            $data = $response->json();

            // Fetch user info to get the nickname and seller_id
            $userResponse = Http::withToken($data['access_token'])->get('https://api.mercadolibre.com/users/me');
            $userData = $userResponse->json();

            // CRITICAL: We update or create a record SPECIFIC to this account (seller_id)
            // This prevents overwriting the configuration record if it's the first connection
            // and allows multiple accounts to share the same Keys.
            $integration = Integration::updateOrCreate(
                [
                    'company_id' => $config->company_id,
                    'platform' => Integration::PLATFORM_MERCADO_LIVRE,
                    'seller_id' => (string) $data['user_id']
                ],
                [
                    'app_id' => $config->app_id,
                    'client_secret' => $config->client_secret,
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'token_expires_at' => now()->addSeconds($data['expires_in']),
                    'expires_at' => now()->addSeconds($data['expires_in']),
                    'external_user_id' => (string) $data['user_id'],
                    'external_nickname' => $userData['nickname'] ?? null,
                    'account_nickname' => $userData['nickname'] ?? null,
                    'status' => 'active',
                    'is_active' => true
                ]
            );

            return redirect()->route('marketplaces.accounts.index')->with('success', 'Mercado Livre conectado com sucesso!');
        }

        Log::error("Erro OAuth ML: " . $response->body());
        return redirect()->route('marketplaces.accounts.index')->with('error', 'Falha ao conectar: ' . ($response->json()['message'] ?? 'Erro desconhecido'));
    }

    /**
     * Salva Developer Token + Client ID/Secret do Google Ads (pedido do
     * cliente 04/08/2026 — integração real via API, não upload). Mesmo
     * desenho de updateKeys(), com o campo a mais que só o Google Ads exige.
     */
    public function updateGoogleAdsKeys(Request $request)
    {
        $request->validate([
            'developer_token' => ['required', 'string'],
            'app_id' => ['required', 'string'], // Client ID (OAuth2)
            'client_secret' => ['required', 'string'],
            'login_customer_id' => ['nullable', 'string'],
        ]);

        $integration = Integration::where('company_id', Auth::user()->company_id)
            ->where('platform', Integration::PLATFORM_GOOGLE_ADS)
            ->whereNull('seller_id') // linha de configuração (sem conta ainda), distinta das contas já conectadas
            ->first();

        $payload = [
            'company_id' => Auth::user()->company_id,
            'platform' => Integration::PLATFORM_GOOGLE_ADS,
            'developer_token' => $request->developer_token,
            'app_id' => $request->app_id,
            'client_secret' => $request->client_secret,
            'login_customer_id' => $request->login_customer_id,
            'status' => 'pending_auth',
        ];

        if ($integration) {
            $integration->update($payload);
        } else {
            Integration::create($payload);
        }

        return redirect()->back()->with('success', 'Credenciais do Google Ads salvas. Agora clique em "Autorizar".');
    }

    public function redirectToGoogleAds()
    {
        $integration = Integration::where('company_id', Auth::user()->company_id)
            ->where('platform', Integration::PLATFORM_GOOGLE_ADS)
            ->whereNull('seller_id')
            ->first();

        if (!$integration || !$integration->developer_token || !$integration->app_id || !$integration->client_secret) {
            return redirect()->route('settings.integrations')->with('error', 'Preencha e salve o Developer Token, Client ID e Client Secret antes de autorizar.');
        }

        $service = new \App\Services\GoogleAds\GoogleAdsApiService();
        $url = $service->authorizationUrl($integration->app_id, route('google-ads.callback'), (string) $integration->id);

        return Inertia::location($url);
    }

    public function handleGoogleAdsCallback(Request $request, \App\Services\GoogleAds\GoogleAdsApiService $service)
    {
        $code = $request->query('code');
        $state = $request->query('state');
        $error = $request->query('error');

        if ($error) {
            return redirect()->route('settings.integrations')->with('error', 'Autorização do Google Ads cancelada: ' . $error);
        }
        if (!$code || !$state) {
            return redirect()->route('settings.integrations')->with('error', 'Autorização do Google Ads cancelada.');
        }

        $config = Integration::find($state);
        if (!$config || $config->platform !== Integration::PLATFORM_GOOGLE_ADS) {
            return redirect()->route('settings.integrations')->with('error', 'Configuração do Google Ads não encontrada — salve as credenciais de novo e tente autorizar novamente.');
        }

        try {
            $tokens = $service->exchangeCode($config->app_id, $config->client_secret, $code, route('google-ads.callback'));
        } catch (\Throwable $e) {
            return redirect()->route('settings.integrations')->with('error', 'Falha ao conectar com o Google Ads: ' . $e->getMessage());
        }

        // Grava o token na linha de config pra já poder chamar a API e listar as contas acessíveis.
        $config->forceFill([
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'] ?? $config->refresh_token, // Google só devolve refresh_token na 1ª autorização com prompt=consent
            'token_expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
            'expires_at' => now()->addSeconds((int) ($tokens['expires_in'] ?? 3600)),
        ])->save();

        if (!$config->refresh_token) {
            return redirect()->route('settings.integrations')->with('error', 'Google não devolveu um refresh_token — desconecte o app em myaccount.google.com/permissions e autorize de novo.');
        }

        try {
            $customerIds = $service->listAccessibleCustomers($config);
        } catch (\Throwable $e) {
            Log::error('Google Ads: falha ao listar contas acessíveis: ' . $e->getMessage());
            return redirect()->route('settings.integrations')->with('error', 'Conectado, mas falhou ao listar as contas do Google Ads: ' . $e->getMessage());
        }

        if (empty($customerIds)) {
            return redirect()->route('settings.integrations')->with('error', 'Conectado, mas nenhuma conta de Google Ads acessível foi encontrada para esse login.');
        }

        $connected = 0;
        foreach ($customerIds as $customerId) {
            $name = $service->fetchCustomerName($config, $customerId);

            Integration::updateOrCreate(
                [
                    'company_id' => $config->company_id,
                    'platform' => Integration::PLATFORM_GOOGLE_ADS,
                    'seller_id' => $customerId,
                ],
                [
                    'app_id' => $config->app_id,
                    'client_secret' => $config->client_secret,
                    'developer_token' => $config->developer_token,
                    'login_customer_id' => $config->login_customer_id,
                    'access_token' => $config->access_token,
                    'refresh_token' => $config->refresh_token,
                    'token_expires_at' => $config->token_expires_at,
                    'expires_at' => $config->expires_at,
                    'external_user_id' => $customerId,
                    'external_nickname' => $name,
                    'account_nickname' => $name ?: $customerId,
                    'status' => 'active',
                    'is_active' => true,
                ]
            );
            $connected++;
        }

        return redirect()->route('settings.integrations')->with('success', "Google Ads conectado! {$connected} conta(s) encontrada(s).");
    }

    public function updateFinance(Request $request)
    {
        $user = Auth::user();
        $user->company->update([
            'tax_rate' => $request->tax_rate,
            'operational_rate' => $request->operational_rate
        ]);

        return redirect()->back()->with('success', 'Regras financeiras atualizadas!');
    }

    public function deleteIntegration($id)
    {
        $integration = Integration::where('company_id', Auth::user()->company_id)->findOrFail($id);
        $integration->delete();
        return redirect()->back()->with('success', 'Integração removida.');
    }

    public function handleWebhook(Request $request, $platform)
    {
        Log::info("Webhook recebido de $platform", $request->all());
        return response()->json(['status' => 'received']);
    }
}