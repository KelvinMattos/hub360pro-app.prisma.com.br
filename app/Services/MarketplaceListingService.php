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

    /**
     * Sincroniza os anúncios de uma credencial e devolve um resultado
     * estruturado — antes, qualquer falha (ex.: token expirado) era engolida
     * num catch+log e o controller sempre respondia sucesso, escondendo do
     * usuário que nada foi sincronizado.
     *
     * @return array{ok: bool, imported: int, updated: int, errors: array<string>, message: string}
     */
    public function syncListings(Integration $credential): array
    {
        try {
            $adapter = $this->manager->adapter($credential);
            $externalProducts = $adapter->fetchProducts($credential);
        } catch (\Throwable $e) {
            Log::error("Error syncing listings for credential {$credential->id}: " . $e->getMessage());
            return [
                'ok' => false,
                'imported' => 0,
                'updated' => 0,
                'errors' => [$e->getMessage()],
                'message' => 'Falha ao sincronizar com o marketplace: ' . $e->getMessage(),
            ];
        }

        $imported = 0;
        $updated = 0;
        $errors = [];

        foreach ($externalProducts as $p) {
            try {
                // Sincronização robusta vinculando por SKU ou ID Externo
                $product = Product::updateOrCreate(
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
                $product->wasRecentlyCreated ? $imported++ : $updated++;
            } catch (\Throwable $e) {
                Log::error("Error syncing product {$p['external_id']} for credential {$credential->id}: " . $e->getMessage());
                $errors[] = "Produto {$p['external_id']}: " . $e->getMessage();
            }
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'message' => sprintf(
                'Sincronização concluída: %d criado(s), %d atualizado(s)%s.',
                $imported,
                $updated,
                empty($errors) ? '' : ' — ' . count($errors) . ' erro(s), veja o log.'
            ),
        ];
    }
}
