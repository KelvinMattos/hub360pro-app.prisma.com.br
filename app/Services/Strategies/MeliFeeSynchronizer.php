<?php

namespace App\Services\Strategies;

use App\Models\Product;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

class MeliFeeSynchronizer
{
    // Taxa atualizada conforme regras ML (ajustar se houver mudança anual)
    const FIXED_FEE_VALUE = 6.75; 

    public function getSimulationData(Product $product)
    {
        if (!$product->company) return null;
        $integration = $product->company->integrations()->where('platform', 'mercadolibre')->first();
        if (!$integration) return null;

        // 1. Busca Taxas de Venda (Listing Prices)
        $listingFees = $this->fetchListingPrices($product->sale_price, $product->category_id, $integration);

        // 2. Busca Custo Real de Envio
        $shippingData = $this->getRealShippingCost($product->external_id, $integration);

        // 3. Busca Árvore de Categoria
        $categoryPath = $this->getCategoryTree($product->category_id);

        return [
            'listing_fees' => $listingFees,
            'shipping' => $shippingData,
            'category_tree' => $categoryPath
        ];
    }

    private function fetchListingPrices($price, $categoryId, Integration $integration)
    {
        $simPrice = $price > 0 ? $price : 100;
        $url = "https://api.mercadolibre.com/sites/MLB/listing_prices";
        $response = Http::withToken($integration->access_token)->get($url, [
            'price' => $simPrice,
            'category_id' => $categoryId
        ]);

        if ($response->failed()) return [];

        $data = $response->json();
        $result = [];

        foreach (['gold_special', 'gold_pro'] as $type) {
            $feeNode = collect($data)->firstWhere('listing_type_id', $type);
            if ($feeNode) {
                $totalAmount = (float)$feeNode['sale_fee_amount'];
                $result[$type] = [
                    'rate' => ($totalAmount / $simPrice) * 100, // %
                    'fixed_fee' => self::FIXED_FEE_VALUE
                ];
            }
        }
        return $result;
    }

    private function getRealShippingCost($mlbId, Integration $integration)
    {
        $url = "https://api.mercadolibre.com/items/{$mlbId}/shipping_options/free";
        $response = Http::withToken($integration->access_token)->get($url);

        $default = ['list_cost' => 0, 'cost' => 0, 'discount_ratio' => 0];
        if ($response->failed()) return $default;

        $data = $response->json();
        $rule = $data['coverage']['all_country'] ?? null;

        if (!$rule) return $default;

        $listCost = (float)($rule['list_cost'] ?? 0);
        $finalCost = (float)($rule['cost'] ?? 0);
        $discountRatio = ($listCost > 0) ? 1 - ($finalCost / $listCost) : 0;

        return [
            'list_cost' => $listCost,
            'cost' => $finalCost,
            'discount_ratio' => $discountRatio
        ];
    }

    private function getCategoryTree($categoryId)
    {
        // Cachear isso seria ideal em produção
        $response = Http::get("https://api.mercadolibre.com/categories/{$categoryId}");
        if ($response->successful()) {
            $path = $response->json()['path_from_root'] ?? [];
            return collect($path)->pluck('name')->implode(' > ');
        }
        return $categoryId;
    }
    
    public function syncProductFees(Product $product) {}
}