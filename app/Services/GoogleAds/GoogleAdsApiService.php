<?php

namespace App\Services\GoogleAds;

use App\Models\Integration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente REST da Google Ads API (pedido do cliente 04/08/2026: integração
 * de verdade via API, não upload de planilha).
 *
 * Usa a interface REST (https://googleads.googleapis.com), não a lib oficial
 * `google-ads-php` — essa lib usa gRPC por padrão, que costuma exigir a
 * extensão `grpc` do PHP (não é garantida em hospedagem cPanel compartilhada,
 * ver CLAUDE.md — "worker não é confiável no cPanel"). A REST API é o mesmo
 * contrato, documentado e suportado oficialmente pela Google, só que via
 * HTTP simples — funciona com o `Http` facade do Laravel sem dependência nova.
 *
 * IMPORTANTE (CLAUDE.md §2.4): o proxy de saída deste ambiente de
 * desenvolvimento bloqueia `developers.google.com`/`googleads.googleapis.com`
 * (mesma política que já bloqueia `netshoes.com.br`), então o formato exato
 * das requisições/respostas foi montado a partir da documentação pública
 * (via busca, não fetch direto) e é validado aqui só com `Http::fake()` nos
 * testes — não foi possível fazer uma chamada real end-to-end. O parsing da
 * resposta é defensivo (aceita variação de casing) por precaução, mas a
 * validação de verdade só acontece em produção, com o developer token real.
 */
class GoogleAdsApiService
{
    private const OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const SCOPE = 'https://www.googleapis.com/auth/adwords';

    private function apiBase(): string
    {
        $version = config('services.google_ads.version', 'v24');

        return "https://googleads.googleapis.com/{$version}";
    }

    public function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'scope' => self::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent', // garante que o Google devolva refresh_token mesmo em reconexão
            'state' => $state,
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Troca o "code" da tela de consentimento por access_token + refresh_token.
     *
     * @throws \RuntimeException quando o Google recusa a troca (código expirado, redirect_uri divergente, etc.)
     */
    public function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri): array
    {
        $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
            'grant_type' => 'authorization_code',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'code' => $code,
            'redirect_uri' => $redirectUri,
        ]);

        if (!$response->successful()) {
            Log::error('Google Ads OAuth: falha ao trocar code por token: ' . $response->body());
            throw new \RuntimeException($this->extractError($response) ?? 'Falha ao trocar o código de autorização por token.');
        }

        $data = $response->json();
        if (empty($data['access_token'])) {
            throw new \RuntimeException('Google não retornou access_token na troca de código.');
        }

        return $data; // access_token, refresh_token (só na 1ª autorização/prompt=consent), expires_in
    }

    /** Renova o access_token usando o refresh_token salvo. Nunca lança — retorna bool, como o padrão já usado no MercadoLivreApiService. */
    public function refreshAccessToken(Integration $integration): bool
    {
        if (!$integration->app_id || !$integration->client_secret) {
            return false;
        }
        if (!$integration->refresh_token) {
            Log::warning("Google Ads: renovação pulada pra integration {$integration->id} — sem refresh_token (precisa reconectar).");
            return false;
        }

        try {
            $response = Http::asForm()->post(self::OAUTH_TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'client_id' => $integration->app_id,
                'client_secret' => $integration->client_secret,
                'refresh_token' => $integration->refresh_token,
            ]);

            if (!$response->successful()) {
                Log::error("Google Ads: falha ao renovar token da integration {$integration->id}: " . $response->body());
                return false;
            }

            $data = $response->json();
            $integration->forceFill([
                'access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
                'expires_at' => now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
            ])->save();

            return true;
        } catch (\Throwable $e) {
            Log::error("Google Ads: exceção ao renovar token da integration {$integration->id}: " . $e->getMessage());
            return false;
        }
    }

    /** Garante um access_token válido antes de qualquer chamada. */
    public function ensureFreshToken(Integration $integration): bool
    {
        if ($integration->access_token && $integration->token_expires_at && !$integration->isNearExpiration()) {
            return true;
        }

        return $this->refreshAccessToken($integration);
    }

    private function headers(Integration $integration): array
    {
        $headers = [
            'developer-token' => $integration->developer_token,
            'Content-Type' => 'application/json',
        ];
        if ($integration->login_customer_id) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $integration->login_customer_id);
        }

        return $headers;
    }

    /**
     * Lista os Customer IDs acessíveis pela conta Google que autorizou o app.
     * Retorna só os IDs (sem hífen) — o endpoint não devolve nome, ver fetchCustomerName().
     *
     * @throws \RuntimeException
     */
    public function listAccessibleCustomers(Integration $integration): array
    {
        if (!$this->ensureFreshToken($integration)) {
            throw new \RuntimeException('Não foi possível obter um access_token válido (refresh_token ausente/inválido — reconecte a conta).');
        }

        $response = Http::withToken($integration->access_token)
            ->withHeaders($this->headers($integration))
            ->get("{$this->apiBase()}/customers:listAccessibleCustomers");

        if (!$response->successful()) {
            throw new \RuntimeException($this->extractError($response) ?? 'Falha ao listar contas acessíveis do Google Ads.');
        }

        $names = $response->json('resourceNames', []);

        // "customers/1234567890" -> "1234567890"
        return array_map(fn ($n) => str_replace('customers/', '', $n), $names);
    }

    /** Nome da conta (customer.descriptive_name), pra exibir na tela em vez do ID cru. Retorna null em caso de falha — não é crítico. */
    public function fetchCustomerName(Integration $integration, string $customerId): ?string
    {
        try {
            $rows = $this->search($integration, $customerId, 'SELECT customer.id, customer.descriptive_name FROM customer LIMIT 1');

            return $rows[0]['customer']['descriptiveName'] ?? $rows[0]['customer']['descriptive_name'] ?? null;
        } catch (\Throwable $e) {
            Log::warning("Google Ads: não deu pra buscar o nome da conta {$customerId}: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Gasto de campanha por dia, no intervalo [since, until] (inclusive).
     * Mapeia pro mesmo formato usado pelo importador manual (ad_spend_daily).
     *
     * @throws \RuntimeException nunca retorna dado parcial fabricado — se a API falhar, propaga (CLAUDE.md §2.2).
     */
    public function fetchCampaignSpend(Integration $integration, string $customerId, string $since, string $until): array
    {
        $query = "SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions "
            . "FROM campaign "
            . "WHERE segments.date BETWEEN '{$since}' AND '{$until}' "
            . "ORDER BY segments.date";

        $rows = $this->search($integration, $customerId, $query);

        $out = [];
        foreach ($rows as $row) {
            $campaign = $row['campaign'] ?? [];
            $segments = $row['segments'] ?? [];
            $metrics = $row['metrics'] ?? [];

            $date = $segments['date'] ?? null;
            $campaignName = $campaign['name'] ?? null;
            if (!$date || !$campaignName) {
                continue; // linha sem os dois campos-chave não é gravável — melhor pular do que gravar lixo
            }

            $costMicros = $metrics['costMicros'] ?? $metrics['cost_micros'] ?? 0;

            $out[] = [
                'date' => $date,
                'campaign_id' => (string) ($campaign['id'] ?? ''),
                'campaign_name' => $campaignName,
                'spend' => round(((float) $costMicros) / 1_000_000, 2),
                'impressions' => isset($metrics['impressions']) ? (int) $metrics['impressions'] : 0,
                'clicks' => isset($metrics['clicks']) ? (int) $metrics['clicks'] : 0,
                'conversions' => isset($metrics['conversions']) ? (int) round((float) $metrics['conversions']) : 0,
            ];
        }

        return $out;
    }

    /** Executa uma query GAQL contra um customer, paginando via nextPageToken. */
    private function search(Integration $integration, string $customerId, string $gaql): array
    {
        if (!$this->ensureFreshToken($integration)) {
            throw new \RuntimeException('Não foi possível obter um access_token válido (refresh_token ausente/inválido — reconecte a conta).');
        }

        $customerId = preg_replace('/\D/', '', $customerId);
        $all = [];
        $pageToken = null;

        do {
            $body = ['query' => $gaql];
            if ($pageToken) {
                $body['pageToken'] = $pageToken;
            }

            $response = Http::withToken($integration->access_token)
                ->withHeaders($this->headers($integration))
                ->post("{$this->apiBase()}/customers/{$customerId}/googleAds:search", $body);

            if (!$response->successful()) {
                throw new \RuntimeException($this->extractError($response) ?? "Falha ao consultar a Google Ads API (customer {$customerId}).");
            }

            $data = $response->json();
            $all = array_merge($all, $data['results'] ?? []);
            $pageToken = $data['nextPageToken'] ?? null;
        } while ($pageToken);

        return $all;
    }

    /** A API do Google Ads retorna erro em `error.message` (padrão Google API), com detalhes em `error.details`. */
    private function extractError($response): ?string
    {
        $body = $response->json();
        if (is_array($body) && isset($body['error']['message'])) {
            return 'Google Ads API: ' . $body['error']['message'];
        }
        // resposta em lote também é possível: [{"error": {...}}]
        if (is_array($body) && isset($body[0]['error']['message'])) {
            return 'Google Ads API: ' . $body[0]['error']['message'];
        }

        return null;
    }
}
