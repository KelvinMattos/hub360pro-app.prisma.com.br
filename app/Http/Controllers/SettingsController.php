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
        
        // Configurações de chaves por plataforma
        $integrations = $company->integrations()
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
                    'platform' => 'mercadolivre',
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