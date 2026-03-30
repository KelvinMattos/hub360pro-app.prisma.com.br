<?php

namespace App\Services;

use App\Models\Integration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class MercadoLivreApiService
{
    protected string $baseUrl = 'https://api.mercadolibre.com';
    protected ?Integration $credential = null;

    public function __construct()
    {
        // Constructor vazio para permitir DI
    }

    public function forCompany(int $companyId): self
    {
        $this->credential = Integration::where('company_id', $companyId)
            ->whereIn('platform', ['mercadolibre', 'mercadolivre'])
            ->orderByRaw('access_token IS NULL ASC') // Prefer records with tokens
            ->first();

        return $this;
    }

    /**
     * Retorna a credencial atual em cache no serviço
     */
    public function getCredential(): ?Integration
    {
        return $this->credential;
    }

    /**
     * Executa uma requisição GET.
     */
    public function get(string $endpoint, array $query = [])
    {
        return $this->request('get', $endpoint, ['query' => $query]);
    }

    /**
     * Executa uma requisição POST.
     */
    public function post(string $endpoint, array $data = [])
    {
        return $this->request('post', $endpoint, ['json' => $data]);
    }

    /**
     * Executa uma requisição PUT.
     */
    public function put(string $endpoint, array $data = [])
    {
        return $this->request('put', $endpoint, ['json' => $data]);
    }

    /**
     * Motor principal de requisições com Auto-Refresh e Retry.
     */
    protected function request(string $method, string $endpoint, array $options = [])
    {
        if (!$this->credential) {
            Log::warning("Meli Request ignored: No credential set for request.");
            return null;
        }

        $this->ensureTokenIsValid();

        if (!$this->credential || !$this->credential->access_token) {
            Log::warning("Meli Request ignored: No active credential found.");
            return null;
        }

        $response = Http::withToken($this->credential->access_token)
            ->$method($this->baseUrl . $endpoint, $options);

        // Se falhar com 401, tenta renovar o token e repetir uma única vez
        // Nota: 401 não entra no retry acima porque não é 429.
        if ($response->status() === 401) {
            Log::warning("Meli Request 401: Token inválido. Tentando refresh e retry para Company: {$this->credential->company_id}");
            if ($this->refreshToken()) {
                return Http::withToken($this->credential->access_token)
                    ->$method($this->baseUrl . $endpoint, $options);
            }
        }

        return $response;
    }

    /**
     * Garante que o access_token é válido, realizando o refresh se necessário.
     */
    public function ensureTokenIsValid(): void
    {
        if (!$this->credential) {
            return;
        }

        if ($this->credential->isNearExpiration()) {
            $this->refreshToken();
        }
    }

    /**
     * Realiza o refresh do token no Mercado Livre.
     * Agora público para permitir renovação programada via console.
     */
    public function refreshToken(): bool
    {
        if (!$this->credential) return false;

        $clientId = $this->credential->app_id;
        $clientSecret = $this->credential->client_secret;
        $refreshToken = $this->credential->refresh_token;

        if (!$clientId || !$refreshToken) {
            Log::warning("Token Refresh skipped for Company {$this->credential->company_id}: Missing ClientID or RefreshToken. User must re-authenticate.");
            return false;
        }

        Log::info("Renovando token Mercado Livre para Company: {$this->credential->company_id}");

        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                $response = Http::asForm()->post($this->baseUrl . '/oauth/token', [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'refresh_token' => $refreshToken,
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    $this->credential->update([
                        'access_token' => $data['access_token'],
                        'refresh_token' => $data['refresh_token'],
                        'expires_at' => now()->addSeconds($data['expires_in']),
                        'token_expires_at' => now()->addSeconds($data['expires_in']),
                        'seller_id' => $data['user_id'] ?? $this->credential->seller_id,
                    ]);

                    Log::info("Token renovado com sucesso!");
                    return true;
                }

                // Se for Rate Limit (429), tenta novamente após um pequeno delay
                if ($response->status() === 429 && $attempts < $maxAttempts - 1) {
                    $attempts++;
                    Log::warning("Meli Token Refresh: Rate limit (429) atingido. Tentativa {$attempts} de {$maxAttempts}. Aguardando...");
                    sleep(1); // Aguarda 1 segundo antes da próxima tentativa
                    continue;
                }

                Log::error("Falha ao renovar token ML: " . $response->body());
                return false;

            } catch (\Exception $e) {
                $attempts++;
                if ($attempts >= $maxAttempts) {
                    Log::error("Exceção ao renovar token ML: " . $e->getMessage());
                    return false;
                }
                Log::warning("Exceção ao renovar token ML. Tentativa {$attempts} de {$maxAttempts}. Erro: " . $e->getMessage());
                sleep(1);
            }
        }

        return false;
    }

    /**
     * Responde uma pergunta no Mercado Livre.
     */
    public function answerQuestion(string $questionId, string $text): void
    {
        $response = $this->post('/answers', [
            'question_id' => $questionId,
            'text' => $text
        ]);

        if (!$response->successful()) {
            throw new \Exception("Falha ao responder pergunta: " . $response->body());
        }
    }
}
