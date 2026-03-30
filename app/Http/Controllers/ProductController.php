<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class ProductController extends Controller
{
    /**
     * Listagem com Lógica Master SKU (Multicontas)
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        if (!$user->company_id) {
            return redirect()->route('dashboard');
        }

        $hasImageUrl = Schema::hasColumn('products', 'image_url');
        $hasExternalId = Schema::hasColumn('products', 'external_id');
        $hasSalePrice = Schema::hasColumn('products', 'sale_price');

        // --- QUERY MASTER SKU ---
        $products = Product::where('company_id', $user->company_id)
            ->where('status', 'active')
            ->select(
                DB::raw($hasExternalId ? 'COALESCE(NULLIF(sku, ""), external_id) as master_sku' : 'sku as master_sku'),
                DB::raw('MAX(title) as title'),
                DB::raw($hasImageUrl ? 'MAX(image_url) as image_url' : 'NULL as image_url'),
                DB::raw('SUM(stock_quantity) as total_stock'),
                DB::raw('COUNT(id) as listings_count'),
                DB::raw($hasSalePrice ? 'AVG(sale_price) as avg_price' : '0 as avg_price')
            )
            ->groupBy('master_sku')
            ->orderBy('total_stock', 'desc')
            ->paginate(20);

        return Inertia::render('Products/Index', [
            'products' => $products
        ]);
    }

    /**
     * Sincronização Massiva de Produtos (Migrada do ProductSyncController legatário)
     */
    public function sync()
    {
        @set_time_limit(600);
        @ini_set('memory_limit', '1024M');

        $user = Auth::user();
        $company = $user->company;

        try {
            // Limpa logs antigos de sistema (manutenção agendada)
            \App\Models\SystemLog::where('company_id', $company->id)
                ->where('created_at', '<', now()->subDays(7))
                ->delete();

            $integrations = \App\Models\Integration::where('company_id', $company->id)
                ->where('platform', 'mercadolibre')
                ->whereNotNull('access_token')
                ->get();

            if ($integrations->isEmpty()) {
                return redirect()->back()->with('warning', 'Nenhuma integração ativa encontrada.');
            }

            $totalSynced = 0;
            $errorsCount = 0;
            $adapter = new \App\Services\Adapters\MercadoLivreAdapter();

            foreach ($integrations as $integration) {
                // 1. Busca IDs ativos
                $allIds = $adapter->fetchAllActiveItemIds($integration);
                if (empty($allIds)) continue;

                // 2. Processa em lotes
                foreach (array_chunk($allIds, 20) as $chunkIds) {
                    $productsData = $adapter->getItemsDetails($integration, $chunkIds);

                    foreach ($productsData as $data) {
                        try {
                            $product = Product::where('company_id', $company->id)
                                ->where(function($q) use ($data) {
                                    $q->where('external_id', $data['external_id'])
                                      ->orWhere('sku', $data['sku']);
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
                                    'image_url' => $data['image_url'],
                                    'stock_quantity' => $data['stock'],
                                    'sale_price' => $data['price'],
                                    'cost_price' => $costPrice,
                                    'category_id' => $data['category_id'],
                                    'listing_type_id' => $data['listing_type_id'],
                                    'permalink' => substr($data['permalink'], 0, 65000),
                                    'status' => $data['status'],
                                    'json_data' => $data['json_data'], 
                                    'updated_at' => now()
                                ]
                            );
                            
                            $prod->pricing()->firstOrCreate(['product_id' => $prod->id], [
                                'cost_price' => $costPrice,
                                'tax_percentage' => $company->tax_rate ?? 6.00
                            ]);

                            $totalSynced++;
                        } catch (\Exception $e) {
                            $errorsCount++;
                            \Illuminate\Support\Facades\Log::error("Erro no produto {$data['external_id']}: " . $e->getMessage());
                        }
                    }
                }
            }

            return redirect()->back()->with('success', "Sincronização Completa! {$totalSynced} produtos atualizados.");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Erro Fatal Sync: " . $e->getMessage());
            return redirect()->back()->with('error', 'Erro Crítico: ' . $e->getMessage());
        }
    }

    public function customers()
    {
        // Placeholder para evitar erro de rota
        return \Inertia\Inertia::render('Dashboard', [
            'salesToday' => 0,
            'ordersCountToday' => 0,
            'salesMonth' => 0
        ]);
    }
}