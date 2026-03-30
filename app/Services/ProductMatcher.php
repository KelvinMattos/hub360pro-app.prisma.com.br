<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductMatcher
{
    /**
     * Encontra um produto local ou importa automaticamente se não existir.
     * Garante que o pedido NUNCA fique sem produto vinculado.
     */
    public function findOrImport(string $sku = null, string $mlbId, string $variationId = null, $integration)
    {
        $companyId = Auth::user()->company_id;

        // 1. TENTATIVA POR SKU (A mais rápida e precisa)
        if (!empty($sku)) {
            $product = Product::where('company_id', $companyId)
                ->where('sku', $sku)
                ->first();
            
            if ($product) return $product;
        }

        // 2. TENTATIVA POR MLB ID (Identificador do Anúncio Pai)
        $product = Product::where('company_id', $companyId)
            ->where('external_id', $mlbId)
            ->first();
        
        if ($product) return $product;

        // 3. TENTATIVA POR VARIAÇÃO (Se o pedido for de uma cor/tamanho específico)
        if (!empty($variationId)) {
            // Busca dentro do JSON de variações
            $product = Product::where('company_id', $companyId)
                ->whereRaw("JSON_SEARCH(json_data->'$.variations[*].id', 'one', ?) IS NOT NULL", [(int)$variationId])
                ->first();

            if ($product) return $product;
        }

        // 4. TENTATIVA POR EAN / GTIN (Busca profunda no JSON)
        // Isso é um pouco mais lento, mas salva quando o SKU está errado
        // Primeiro precisamos pegar o EAN desse item na API do ML para comparar
        try {
            $apiItem = $this->fetchItemFromApi($mlbId, $integration);
            $ean = $this->extractEanFromApiData($apiItem, $variationId);

            if ($ean) {
                // Busca se algum produto local tem esse EAN nos atributos
                $product = Product::where('company_id', $companyId)
                    ->whereRaw("JSON_SEARCH(json_data->'$.attributes[*].value_name', 'one', ?) IS NOT NULL", [$ean])
                    ->first();

                if ($product) return $product;
            }
            
            // 5. ULTIMO RECURSO: AUTO-IMPORTAÇÃO (Cria o produto na hora)
            // Se chegamos aqui, o produto foi vendido mas não existe no Hub.
            // Vamos criá-lo agora para garantir o cálculo financeiro.
            return $this->createProductFromApi($apiItem, $companyId);

        } catch (\Exception $e) {
            Log::error("Erro no ProductMatcher: " . $e->getMessage());
            return null; // Caso extremo de falha de API
        }
    }

    private function fetchItemFromApi($mlbId, $integration)
    {
        $response = Http::withToken($integration->access_token)
            ->get("https://api.mercadolibre.com/items/{$mlbId}");
            
        if ($response->failed()) throw new \Exception("Item não encontrado na API: $mlbId");
        
        return $response->json();
    }

    private function extractEanFromApiData($data, $variationId)
    {
        // Se for variação, busca o GTIN dentro da variação específica
        if ($variationId && isset($data['variations'])) {
            foreach ($data['variations'] as $variation) {
                if ($variation['id'] == $variationId && isset($variation['attributes'])) {
                    foreach ($variation['attributes'] as $attr) {
                        if ($attr['id'] === 'GTIN') return $attr['value_name'];
                    }
                }
            }
        }

        // Se for item simples ou não achou na variação, busca no pai
        if (isset($data['attributes'])) {
            foreach ($data['attributes'] as $attr) {
                if ($attr['id'] === 'GTIN') return $attr['value_name'];
            }
        }

        return null;
    }

    private function createProductFromApi($data, $companyId)
    {
        // Criação de emergência para não perder o vínculo
        return Product::create([
            'company_id' => $companyId,
            'external_id' => $data['id'],
            'title' => $data['title'],
            'sku' => $data['seller_custom_field'] ?? $data['id'], // Usa MLB se não tiver SKU
            'price' => $data['price'],
            'sale_price' => $data['price'],
            'cost_price' => 0, // Custo zero (usuário precisará preencher depois)
            'stock_quantity' => $data['available_quantity'],
            'status' => $data['status'] === 'active' ? 'active' : 'paused',
            'listing_type_id' => $data['listing_type_id'],
            'json_data' => $data
        ]);
    }
}