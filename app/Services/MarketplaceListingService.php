<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

class MarketplaceListingService
{
    protected MarketplaceManager $manager;

    public function __construct(MarketplaceManager $manager)
    {
        $this->manager = $manager;
    }

    public function syncListings(Integration $credential)
    {
        try {
            $adapter = $this->manager->adapter($credential);
            $externalProducts = $adapter->fetchProducts($credential);

            foreach ($externalProducts as $p) {
                // Sincronização robusta vinculando por SKU ou ID Externo
                Product::updateOrCreate(
                    [
                        'company_id' => $credential->company_id, 
                        'external_id' => $p['external_id']
                    ],
                    [
                        'sku' => $p['sku'],
                        'ean' => $p['ean'],
                        'title' => $p['title'],
                        'brand' => $p['brand'],
                        'price' => $p['price'],
                        'sale_price' => $p['price'],
                        'stock_quantity' => $p['stock'],
                        'status' => $p['status'],
                        'condition' => $p['condition'],
                        'permalink' => $p['permalink'],
                        'image_url' => $p['thumbnail'], // Thumbnail como imagem principal por padrão
                        'thumbnail' => $p['thumbnail'],
                        'video_id' => $p['video_id'],
                        'category_id' => $p['ml_category_id'],
                        'listing_type_id' => $p['listing_type_id'],
                        'shipping_mode' => $p['shipping_mode'],
                        'free_shipping' => $p['free_shipping'],
                        'attributes' => $p['attributes'],
                        'variations' => $p['variations'],
                        'json_data' => [
                            'ml_item_id' => $p['external_id'],
                            'ml_status' => $p['status'],
                            'last_sync' => now()->toDateTimeString()
                        ]
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error("Error syncing listings for credential {$credential->id}: " . $e->getMessage());
        }
    }
}
