<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Product;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Customer;
use App\Models\Integration;
use App\Models\SystemLog;
use App\Services\Adapters\MercadoLivreAdapter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductSyncController extends Controller
{
    /**
     * Rota: /products/sync
     * Executa a atualização massiva de produtos com tratamento de falhas individuais.
     */
    public function syncAll()
    {
        // Aumenta limites
        @set_time_limit(600); // 10 minutos
        @ini_set('memory_limit', '1024M');

        $user = Auth::user();
        $company = $user->company;

        try {
            // Limpa logs antigos
            SystemLog::where('company_id', $company->id)->where('created_at', '<', now()->subDays(7))->delete();

            $integrations = Integration::where('company_id', $company->id)
                                       ->where('platform', 'mercadolibre')
                                       ->whereNotNull('access_token')
                                       ->get();

            if ($integrations->isEmpty()) {
                return redirect()->back()->with('warning', 'Nenhuma integração ativa encontrada.');
            }

            $totalSynced = 0;
            $errorsCount = 0;
            $adapter = new MercadoLivreAdapter();

            foreach ($integrations as $integration) {
                // 1. Busca TODOS os IDs ativos
                $allIds = $adapter->fetchAllActiveItemIds($integration);
                
                if (empty($allIds)) continue;

                // 2. Processa em lotes de 20
                $chunks = array_chunk($allIds, 20);

                foreach ($chunks as $chunkIds) {
                    $productsData = $adapter->getItemsDetails($integration, $chunkIds);

                    foreach ($productsData as $data) {
                        // --- BLINDAGEM: Try/Catch Individual por Produto ---
                        try {
                            // Tenta encontrar produto existente
                            $product = Product::where('company_id', $company->id)
                                ->where(function($q) use ($data) {
                                    $q->where('external_id', $data['external_id'])
                                      ->orWhere('sku', $data['sku']);
                                })->first();

                            // Preserva custo se já existir
                            $costPrice = ($product && $product->cost_price > 0) 
                                ? $product->cost_price 
                                : ($data['price'] * 0.5); 

                            // Atualiza ou Cria
                            $prod = Product::updateOrCreate(
                                [
                                    'company_id' => $company->id,
                                    'external_id' => $data['external_id']
                                ],
                                [
                                    'sku' => $data['sku'],
                                    'title' => $data['title'],
                                    'image_url' => $data['image_url'],
                                    'stock_quantity' => $data['stock'],
                                    'sale_price' => $data['price'],
                                    'cost_price' => $costPrice,
                                    
                                    // DADOS CRÍTICOS
                                    'category_id' => $data['category_id'],
                                    'listing_type_id' => $data['listing_type_id'],
                                    'permalink' => substr($data['permalink'], 0, 65000), // Segurança extra mesmo com TEXT
                                    'status' => $data['status'],
                                    'json_data' => $data['json_data'], 
                                    'updated_at' => now()
                                ]
                            );
                            
                            // Inicializa PricingConfig
                            $prod->pricing()->firstOrCreate(['product_id' => $prod->id], [
                                'cost_price' => $costPrice,
                                'tax_percentage' => $company->tax_rate ?? 6.00
                            ]);

                            $totalSynced++;

                        } catch (\Exception $e) {
                            // Se um produto falhar, conta o erro e LOGA, mas NÃO PARA o loop.
                            $errorsCount++;
                            Log::error("Falha ao sincronizar produto {$data['external_id']}: " . $e->getMessage());
                        }
                    }
                }
            }

            $msg = "Sincronização Completa! {$totalSynced} produtos atualizados.";
            if ($errorsCount > 0) {
                $msg .= " (Houve {$errorsCount} falhas em produtos específicos, verifique os logs).";
            }

            return redirect()->back()->with('success', $msg);

        } catch (\Exception $e) {
            Log::error("Erro Fatal Product Sync: " . $e->getMessage());
            return redirect()->back()->with('error', 'Erro Crítico: ' . $e->getMessage());
        }
    }
}