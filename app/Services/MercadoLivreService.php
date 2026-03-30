<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use App\Models\Integration;
use App\Models\User;
use Carbon\Carbon;

class MercadoLivreService
{
    const API_URL = 'https://api.mercadolibre.com';

    // Recupera a integração do banco para obter App ID e Secret
    private function getIntegration(User $user)
    {
        return Integration::where('company_id', $user->company_id)
                          ->where('platform', 'mercadolibre')
                          ->first();
    }

    public function getAuthUrl(User $user)
    {
        $int = $this->getIntegration($user);
        
        if (!$int || empty($int->client_id)) {
            return '#erro-chaves-nao-configuradas';
        }

        return "https://auth.mercadolibre.com.br/authorization?response_type=code&client_id={$int->client_id}&redirect_uri={$int->redirect_uri}";
    }

    public function handleCallback($code, User $user)
    {
        $int = $this->getIntegration($user);
        if (!$int) return false;

        $response = Http::post(self::API_URL . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'client_id' => $int->client_id,
            'client_secret' => $int->client_secret,
            'code' => $code,
            'redirect_uri' => $int->redirect_uri,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            // Salva o Token na MESMA linha que já tem o client_id
            $int->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => Carbon::now()->addSeconds($data['expires_in']),
                'external_user_id' => $data['user_id'],
            ]);
            
            // Busca apelido
            $me = Http::withToken($data['access_token'])->get(self::API_URL . '/users/me')->json();
            $int->update(['external_nickname' => $me['nickname'] ?? null]);
            
            return true;
        }
        return false;
    }

    public function get($endpoint, User $user)
    {
        $int = $this->getIntegration($user);
        if (!$int || !$int->access_token) return null;

        // Auto Refresh
        if ($int->token_expires_at && Carbon::now()->addMinutes(10)->gt($int->token_expires_at)) {
            $this->refreshToken($int);
            $int->refresh();
        }

        return Http::withToken($int->access_token)->get(self::API_URL . $endpoint)->json();
    }

    protected function refreshToken(Integration $int)
    {
        $response = Http::post(self::API_URL . '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $int->client_id,
            'client_secret' => $int->client_secret,
            'refresh_token' => $int->refresh_token,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $int->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => Carbon::now()->addSeconds($data['expires_in']),
            ]);
        }
    }

    // Método de busca de produtos (Mantido idêntico, só chama o get)
    public function fetchActiveProducts(User $user)
    {
        $int = $this->getIntegration($user);
        if (!$int || !$int->external_user_id) return [];

        $search = $this->get("/users/{$int->external_user_id}/items/search?status=active&limit=50", $user);
        if (empty($search['results'])) return [];

        $ids = implode(',', $search['results']);
        $response = $this->get("/items?ids=$ids&attributes=id,title,price,permalink,thumbnail,attributes,listing_type_id,shipping,available_quantity", $user);

        $products = [];
        foreach ($response as $item) {
            if ($item['code'] !== 200) continue;
            $body = $item['body'];
            $sku = null;
            if(isset($body['attributes'])) {
                foreach ($body['attributes'] as $attr) {
                    if ($attr['id'] === 'SELLER_SKU') { $sku = $attr['value_name']; break; }
                }
            }
            $products[] = [
                'ml_id' => $body['id'],
                'title' => $body['title'],
                'price' => $body['price'],
                'sku'   => $sku,
                'permalink' => $body['permalink'],
                'image' => $body['thumbnail'],
                'stock' => $body['available_quantity'],
                'listing_type' => $body['listing_type_id'],
                'shipping_mode' => $body['shipping']['mode'] ?? null
            ];
        }
        return $products;
    }
}