<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Company; // Necessário para buscar a empresa
use App\Services\ProductMatcher;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Inertia\Inertia;

class OrderController extends Controller
{
    /**
     * Lista os pedidos da empresa logada.
     */
    public function index()
    {
        $orders = Order::where('company_id', Auth::user()->company_id)
            ->with('items')
            ->orderBy('date_created', 'desc')
            ->paginate(20);

        return Inertia::render('Orders/Index', [
            'orders' => $orders
        ]);
    }

    /**
     * Exibe detalhes de um pedido específico.
     */
    public function show($id)
    {
        $order = Order::where('company_id', Auth::user()->company_id)
            ->with(['items.product'])
            ->findOrFail($id);

        return Inertia::render('Orders/Show', [
            'order' => $order
        ]);
    }

    /**
     * Sincroniza um pedido específico e vincula os produtos.
     */
    public function syncSingle($id, ProductMatcher $matcher)
    {
        // 1. Busca o Pedido (garantindo segurança pelo usuário)
        $order = Order::where('company_id', Auth::user()->company_id)->findOrFail($id);

        // CORREÇÃO: Busca a Empresa explicitamente pelo ID
        // Evita o erro "integrations() on null" caso a relação $order->company falhe
        $company = Company::find($order->company_id);

        if (!$company) {
            return response()->json(['error' => 'Empresa vinculada ao pedido não encontrada.'], 400);
        }

        // 2. Busca a integração do Mercado Livre
        $integration = $company->integrations()->where('platform', 'mercadolibre')->first();

        if (!$integration) {
            return response()->json(['error' => 'Integração com Mercado Livre não configurada.'], 400);
        }

        try {
            // 3. Busca dados atualizados do pedido na API do ML
            $response = Http::withToken($integration->access_token)
                ->get("https://api.mercadolibre.com/orders/{$order->external_id}");

            if ($response->failed()) {
                throw new \Exception("Erro ao buscar pedido na API do ML: " . $response->body());
            }

            $data = $response->json();

            DB::beginTransaction();

            // 4. Atualiza Cabeçalho do Pedido
            $order->update([
                'status' => $data['status'],
                'status_detail' => $data['status_detail'] ?? null,
                'total_amount' => $data['total_amount'],
                'date_last_updated' => Carbon::now()
            ]);

            // 5. Processa e VINCULA os Itens
            $order->items()->delete(); // Limpa itens antigos para recriar atualizados

            foreach ($data['order_items'] as $apiItem) {
                $itemData = $apiItem['item'];

                // Usa o ProductMatcher para encontrar ou criar o produto localmente
                $localProduct = $matcher->findOrImport(
                    $itemData['seller_sku'] ?? null, // SKU
                    $itemData['id'], // MLB ID
                    $itemData['variation_id'] ?? null, // Variação ID
                    $integration
                );

                // Cria o item do pedido vinculado
                // (Nota: removemos 'company_id' daqui pois a tabela de itens não costuma ter essa coluna)
                $order->items()->create([
                    'product_id' => $localProduct ? $localProduct->id : null, // ID do produto local (VÍNCULO)
                    'product_title' => $itemData['title'],
                    'sku' => $itemData['seller_sku'] ?? 'SEM-SKU',
                    'quantity' => $apiItem['quantity'],
                    'unit_price' => $apiItem['unit_price'],
                    'full_unit_price' => $apiItem['full_unit_price'],
                    'currency_id' => $apiItem['currency_id'],
                    'external_item_id' => $itemData['id'],
                    'external_variation_id' => $itemData['variation_id'] ?? null,
                    // Congela o custo do produto no momento da venda
                    'cost_price' => $localProduct ? $localProduct->cost_price : 0,
                    'net_margin' => 0
                ]);
            }

            DB::commit();
            return back()->with('success', 'Pedido sincronizado com sucesso!');
        }
        catch (\Exception $e) {
            DB::rollBack();
            // Log do erro para debug se necessário
            // \Log::error($e->getMessage());
            return back()->withErrors(['error' => 'Erro na sincronização: ' . $e->getMessage()]);
        }
    }

    public function printLabel($id)
    {
        return response()->json(['message' => 'Funcionalidade de etiqueta em desenvolvimento.']);
    }

    public function markNotificationsRead()
    {
        return response()->json(['status' => 'success']);
    }
}