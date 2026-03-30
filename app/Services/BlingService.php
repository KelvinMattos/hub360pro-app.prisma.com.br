<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class BlingService
{
    const API_URL = 'https://www.bling.com.br/Api/v3';
    const AUTH_URL = 'https://www.bling.com.br/Api/v3/oauth/token';

    private function getIntegration(User $user)
    {
        return Integration::where('company_id', $user->company_id)
                          ->where('platform', 'bling')
                          ->first();
    }

    public function getAuthUrl(User $user)
    {
        $int = $this->getIntegration($user);
        
        if (!$int || empty($int->client_id)) {
            return '#erro-chaves-bling-faltando';
        }

        $state = md5(time());
        return "https://www.bling.com.br/Api/v3/oauth/authorize?response_type=code&client_id={$int->client_id}&redirect_uri={$int->redirect_uri}&scope=prospects_read&state={$state}";
    }

    public function handleCallback($code, User $user)
    {
        $int = $this->getIntegration($user);
        if (!$int) return false;

        $credentials = base64_encode($int->client_id . ':' . $int->client_secret);

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Basic ' . $credentials,
                'Content-Type' => 'application/x-www-form-urlencoded'
            ])->asForm()->post(self::AUTH_URL, [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $int->redirect_uri
            ]);

            if ($response->successful()) {
                $data = $response->json();
                $int->update([
                    'access_token' => $data['access_token'],
                    'refresh_token' => $data['refresh_token'],
                    'token_expires_at' => Carbon::now()->addSeconds($data['expires_in'] - 60),
                ]);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function fetchProducts(User $user)
    {
        $int = $this->getIntegration($user);
        if (!$int || !$int->access_token) return [];

        $response = Http::withToken($int->access_token)
            ->get(self::API_URL . '/produtos', [
                'pagina' => 1, 'limite' => 100, 'criterio' => 1
            ]);

        if ($response->failed()) return [];

        $data = $response->json();
        $formatted = [];
        if (isset($data['data'])) {
            foreach ($data['data'] as $prod) {
                $formatted[] = [
                    'sku'  => $prod['codigo'] ?? null,
                    'name' => $prod['nome'] ?? 'Sem Nome',
                    'cost' => $prod['precoCusto'] ?? 0,
                    'stock' => 0
                ];
            }
        }
        return $formatted;
    }
}