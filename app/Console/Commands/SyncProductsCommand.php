<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Product;
use App\Models\Integration;
use App\Services\Adapters\MercadoLivreAdapter;
use Illuminate\Support\Facades\Log;

class SyncProductsCommand extends Command
{
    protected $signature = 'products:sync';
    protected $description = 'Sincroniza automaticamente os produtos do Mercado Livre de todas as integrações cadastradas.';

    public function handle()
    {
        $this->info("Iniciando sincronização global de produtos do Mercado Livre...");

        $integrations = Integration::where('platform', 'mercadolivre')
            ->whereNotNull('access_token')
            ->get();

        $adapter = new MercadoLivreAdapter();
        $totalSynced = 0;

        foreach ($integrations as $integration) {
            $company = $integration->company;
            if (!$company) continue;

            $this->info("Sincronizando produtos para: {$integration->account_nickname} (Empresa: {$company->name})");

            try {
                $productsData = $adapter->fetchProducts($integration);
                if (empty($productsData)) continue;

                foreach ($productsData as $data) {
                    try {
                        $product = Product::where('company_id', $company->id)
                            ->where(function($q) use ($data) {
                                $q->where('external_id', $data['external_id']);
                                if (!empty($data['sku'])) {
                                    $q->orWhere('sku', $data['sku']);
                                }
                            })->first();

                        $costPrice = ($product && $product->cost_price > 0) 
                            ? $product->cost_price 
                            : ($data['price'] * 0.5); 

                        $prod = Product::updateOrCreate(
                            [
                                'company_id' => $company->id,
                                'external_id' => $data['external_id']
                            ],
                            [
                                'sku' => $data['sku'],
                                'title' => $data['title'],
                                'image_url' => $data['thumbnail'] ?? null,
                                'stock_quantity' => $data['stock'],
                                'sale_price' => $data['price'],
                                'cost_price' => $costPrice,
                                'category_id' => $data['ml_category_id'] ?? null,
                                'listing_type_id' => $data['listing_type_id'] ?? null,
                                'permalink' => isset($data['permalink']) ? substr($data['permalink'], 0, 65000) : null,
                                'status' => $data['status'],
                                'json_data' => $data, 
                                'updated_at' => now()
                            ]
                        );
                        
                        $prod->pricing()->firstOrCreate(['product_id' => $prod->id], [
                            'cost_price' => $costPrice,
                            'tax_percentage' => $company->tax_rate ?? 6.00
                        ]);

                        $totalSynced++;
                    } catch (\Exception $e) {
                        Log::error("Erro no produto {$data['external_id']}: " . $e->getMessage());
                    }
                }
                $this->info("Sincronização concluída com sucesso para a integração.");
            } catch (\Exception $e) {
                Log::error("Erro de sincronia de produtos para a conta {$integration->id}: " . $e->getMessage());
                $this->error("Erro: " . $e->getMessage());
            }
        }

        $this->info("Sincronização geral de produtos concluída: {$totalSynced} produtos integrados ou atualizados.");
    }
}
