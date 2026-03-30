<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

class StockSyncService
{
    protected MarketplaceManager $manager;

    public function __construct(MarketplaceManager $manager)
    {
        $this->manager = $manager;
    }

    public function syncStockToAllMarketplaces(Product $product)
    {
        $credentials = Integration::where('company_id', $product->company_id)
            ->where('is_active', true)
            ->where('platform', '!=', 'bling')
            ->get();

        foreach ($credentials as $credential) {
            $this->syncToMarketplace($product, $credential);
        }
    }

    public function syncToMarketplace(Product $product, Integration $credential)
    {
        try {
            $adapter = $this->manager->adapter($credential);
            
            // In a real scenario, we'd have a mapping table between Product and Marketplace Item ID
            $marketplaceItemId = $product->json_data['ml_item_id'] ?? null;

            if ($marketplaceItemId) {
                $adapter->updateStock($credential, $marketplaceItemId, $product->stock);
                Log::info("Stock synced for product {$product->id} to marketplace {$credential->platform}");
            }
        } catch (\Exception $e) {
            Log::error("Stock sync failed for product {$product->id} on {$credential->platform}: " . $e->getMessage());
        }
    }
}
