<?php

namespace App\Services\Adapters;

use App\Contracts\MarketplaceAdapter;
use App\Models\Integration;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon as CarbonLib;

class MercadoLivreAdapter implements MarketplaceAdapter
{
    protected string $baseUrl = 'https://api.mercadolibre.com';

    public function checkConnection(Integration $credential): bool
    {
        try {
            $response = $this->request($credential, 'get', '/users/me');
            return $response->successful();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function fetchOrders(Integration $credential, $limit = 50)
    {
        $response = $this->request($credential, 'get', '/orders/search', [
            'seller' => $credential->seller_id,
            'sort' => 'date_desc',
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            return [];
        }

        $results = $response->json()['results'] ?? [];
        $orders = [];

        foreach ($results as $orderData) {
            $orders[] = [
                'external_id' => $orderData['id'],
                'status' => $orderData['status'],
                'total_amount' => $orderData['total_amount'],
                'buyer_nickname' => $orderData['buyer']['nickname'] ?? null,
                'date_created' => CarbonLib::parse($orderData['date_created']),
                'raw_data' => $orderData
            ];
        }

        return $orders;
    }

    public function fetchProducts(Integration $credential)
    {
        $response = $this->request($credential, 'get', "/users/{$credential->seller_id}/items/search", [
            'status' => 'active',
            'limit' => 100,
        ]);

        if ($response->failed()) {
            return [];
        }

        $itemIds = $response->json()['results'] ?? [];
        if (empty($itemIds)) return [];

        $chunks = array_chunk($itemIds, 20);
        $products = [];

        foreach ($chunks as $chunk) {
            $ids = implode(',', $chunk);
            // Upgrade: include_attributes=all traz a lista completa de atributos e variações
            $detailsResponse = $this->request($credential, 'get', "/items", [
                'ids' => $ids,
                'include_attributes' => 'all'
            ]);
            
            if ($detailsResponse->successful()) {
                foreach ($detailsResponse->json() as $itemBody) {
                    $item = $itemBody['body'] ?? null;
                    if (!$item) continue;

                    // Busca SKU nos atributos
                    $sku = null;
                    $ean = null;
                    $brand = null;
                    foreach ($item['attributes'] ?? [] as $attr) {
                        if ($attr['id'] === 'SELLER_SKU') $sku = $attr['value_name'];
                        if ($attr['id'] === 'GTIN' || $attr['id'] === 'EAN') $ean = $attr['value_name'];
                        if ($attr['id'] === 'BRAND') $brand = $attr['value_name'];
                    }

                    $products[] = [
                        'external_id' => $item['id'],
                        'title' => $item['title'],
                        'price' => $item['price'],
                        'stock' => $item['available_quantity'],
                        'sku' => $sku,
                        'ean' => $ean,
                        'brand' => $brand,
                        'permalink' => $item['permalink'],
                        'thumbnail' => $item['thumbnail'],
                        'video_id' => $item['video_id'] ?? null,
                        'condition' => $item['condition'],
                        'status' => $item['status'],
                        'listing_type' => $item['listing_type_id'],
                        'listing_type_id' => $item['listing_type_id'],
                        'ml_category_id' => $item['category_id'],
                        'shipping_mode' => $item['shipping']['mode'] ?? null,
                        'free_shipping' => $item['shipping']['free_shipping'] ?? false,
                        'attributes' => $item['attributes'] ?? [],
                        'variations' => $item['variations'] ?? []
                    ];
                }
            }
        }

        return $products;
    }

    public function getShipmentLabel(Integration $credential, string $shippingId)
    {
        $response = $this->request($credential, 'get', "/shipment_labels", [
            'shipment_ids' => $shippingId,
            'response_type' => 'pdf'
        ]);

        return $response->successful() ? $response->body() : null;
    }

    public function updatePrice(Integration $credential, $external_id, $price, $promoPrice = null)
    {
        $data = ['price' => $price];
        
        if ($promoPrice) {
            $data['promo_price'] = $promoPrice;
        }

        $response = $this->request($credential, 'put', "/items/{$external_id}", $data);

        return $response->successful();
    }

    public function updateStock(Integration $credential, string $externalId, int $stock)
    {
        $response = $this->request($credential, 'put', "/items/{$externalId}", [
            'available_quantity' => $stock
        ]);

        return $response->successful();
    }

    public function fetchQuestions(Integration $credential)
    {
        $response = $this->request($credential, 'get', "/questions/search", [
            'seller_id' => $credential->seller_id,
            'status' => 'unanswered',
        ]);

        return $response->successful() ? ($response->json()['questions'] ?? []) : [];
    }

    public function answerQuestion(Integration $credential, string $questionId, string $text)
    {
        $response = $this->request($credential, 'post', "/answers", [
            'question_id' => $questionId,
            'text' => $text
        ]);

        return $response->successful();
    }

    public function fetchMessages(Integration $credential, string $orderId)
    {
        $response = $this->request($credential, 'get', "/messages/packs/{$orderId}/sellers/{$credential->seller_id}");
        return $response->successful() ? ($response->json()['messages'] ?? []) : [];
    }

    public function sendMessage(Integration $credential, string $orderId, string $text)
    {
        // MELI requires a specific format for post-sale messages
        // This is a simplified version
        $response = $this->request($credential, 'post', "/messages/packs/{$orderId}/sellers/{$credential->seller_id}", [
            'text' => $text
        ]);

        return $response->successful();
    }

    public function fetchHistoricalBatch(Integration $credential, $olderThanDate)
    {
        // MELI date format for search: YYYY-MM-DDTHH:mm:ss.000-04:00
        // We want orders older than $olderThanDate
        $dateTo = $olderThanDate->toIso8601String();
        
        $response = $this->request($credential, 'get', '/orders/search', [
            'seller' => $credential->seller_id,
            'sort' => 'date_desc',
            'order.date_created.to' => $dateTo,
            'limit' => 50,
        ]);

        if ($response->failed()) {
            return [];
        }

        $results = $response->json()['results'] ?? [];
        $orders = [];

        foreach ($results as $orderData) {
            $orders[] = [
                'external_id' => $orderData['id'],
                'status' => $orderData['status'],
                'total_amount' => $orderData['total_amount'],
                'buyer_nickname' => $orderData['buyer']['nickname'] ?? null,
                'date_created' => CarbonLib::parse($orderData['date_created']),
                'raw_data' => $orderData
            ];
        }

        return $orders;
    }

    /**
     * Helper request method with auto-token refresh logic.
     */
    protected function request(Integration $credential, string $method, string $endpoint, array $data = [])
    {
        if (method_exists($credential, 'isNearExpiration') && $credential->isNearExpiration()) {
            $this->refreshToken($credential);
        }

        $url = $this->baseUrl . $endpoint;
        $request = Http::withToken($credential->access_token);

        if ($method === 'get') {
            $response = $request->get($url, $data);
        } else {
            $response = $request->$method($url, $data);
        }

        // If 401, try to refresh once and retry
        if ($response->status() === 401) {
            $this->refreshToken($credential);
            $request = Http::withToken($credential->access_token);
            $response = ($method === 'get') ? $request->get($url, $data) : $request->$method($url, $data);
        }

        return $response;
    }

    protected function refreshToken(Integration $credential): void
    {
        $response = Http::asForm()->post($this->baseUrl . '/oauth/token', [
            'grant_type' => 'refresh_token',
            'client_id' => $credential->app_id ?? config('services.mercadolivre.client_id'),
            'client_secret' => $credential->client_secret ?? config('services.mercadolivre.client_secret'),
            'refresh_token' => $credential->refresh_token,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            $credential->update([
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in']),
                'expires_at' => now()->addSeconds($data['expires_in']),
            ]);
        } else {
            Log::error("Failed to refresh ML token for credential ID: {$credential->id}");
            throw new \Exception("Mercado Livre authentication failed.");
        }
    }
}