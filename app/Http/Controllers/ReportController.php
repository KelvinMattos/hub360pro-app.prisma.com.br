<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Order;
use App\Models\Product;
use App\Models\OrderItem;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;
use Inertia\Inertia;

class ReportController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $cid = $user->company_id;

        // Schema de produção diverge do model (CLAUDE.md §4) — resolve as colunas
        // reais em vez de assumir que existem, pra não derrubar a tela com 500.
        $orderCols = Schema::getColumnListing('orders');
        $hasOrderCol = fn ($c) => in_array($c, $orderCols, true);
        $dateCol = $hasOrderCol('date_created') ? 'date_created' : ($hasOrderCol('order_date') ? 'order_date' : 'created_at');
        $profitSql = $hasOrderCol('net_profit') ? 'SUM(net_profit)' : '0';
        $hasIntegrationId = $hasOrderCol('integration_id');

        // 1. Definição do Período (Range)
        $range = $request->get('range', 'this_month');

        switch ($range) {
            case 'today':
                $start = Carbon::today();
                $end = Carbon::now()->endOfDay();
                $prevStart = Carbon::yesterday();
                $prevEnd = Carbon::yesterday()->endOfDay();
                $label = 'Hoje';
                break;
            case 'yesterday':
                $start = Carbon::yesterday();
                $end = Carbon::yesterday()->endOfDay();
                $prevStart = Carbon::today()->subDays(2);
                $prevEnd = Carbon::today()->subDays(2)->endOfDay();
                $label = 'Ontem';
                break;
            case 'last_7_days':
                $start = Carbon::now()->subDays(7)->startOfDay();
                $end = Carbon::now()->endOfDay();
                $prevStart = Carbon::now()->subDays(14)->startOfDay();
                $prevEnd = Carbon::now()->subDays(7)->endOfDay();
                $label = 'Últimos 7 Dias';
                break;
            case 'last_month':
                $start = Carbon::now()->subMonth()->startOfMonth();
                $end = Carbon::now()->subMonth()->endOfMonth();
                $prevStart = Carbon::now()->subMonths(2)->startOfMonth();
                $prevEnd = Carbon::now()->subMonths(2)->endOfMonth();
                $label = 'Mês Passado';
                break;
            case 'custom':
                $start = $request->date_start ?Carbon::parse($request->date_start)->startOfDay() : Carbon::now()->startOfMonth();
                $end = $request->date_end ?Carbon::parse($request->date_end)->endOfDay() : Carbon::now()->endOfMonth();
                $diff = $start->diffInDays($end);
                $prevStart = $start->copy()->subDays($diff);
                $prevEnd = $start->copy();
                $label = 'Personalizado';
                break;
            case 'this_month':
            default:
                $start = Carbon::now()->startOfMonth();
                $end = Carbon::now()->endOfMonth();
                $prevStart = Carbon::now()->subMonth()->startOfMonth();
                $prevEnd = Carbon::now()->subMonth()->endOfMonth();
                $label = 'Este Mês';
                break;
        }

        // 2. Métricas Principais (KPIs) com Comparativo
        $currentStats = Order::where('company_id', $cid)
            ->whereBetween($dateCol, [$start, $end])
            ->where('status', '!=', 'cancelled')
            ->selectRaw("COUNT(*) as total_orders, SUM(total_amount) as revenue, AVG(total_amount) as ticket, {$profitSql} as profit")
            ->first();

        $prevStats = Order::where('company_id', $cid)
            ->whereBetween($dateCol, [$prevStart, $prevEnd])
            ->where('status', '!=', 'cancelled')
            ->selectRaw('SUM(total_amount) as revenue')
            ->first();

        // Cálculo de Crescimento (%)
        $growth = 0;
        if (($prevStats->revenue ?? 0) > 0) {
            $growth = (($currentStats->revenue - $prevStats->revenue) / $prevStats->revenue) * 100;
        }
        elseif ($currentStats->revenue > 0) {
            $growth = 100; // Cresceu do zero
        }

        // 3. Gráfico de Evolução (Diário)
        $evolution = Order::where('company_id', $cid)
            ->whereBetween($dateCol, [$start, $end])
            ->where('status', '!=', 'cancelled')
            ->selectRaw("DATE({$dateCol}) as date, SUM(total_amount) as total")
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $chartLabels = $evolution->pluck('date')->map(fn($d) => Carbon::parse($d)->format('d/m'));
        $chartValues = $evolution->pluck('total');

        // 4. Funil de Status
        $funnelRaw = Order::where('company_id', $cid)
            ->whereBetween($dateCol, [$start, $end])
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $funnel = [
            'paid' => ($funnelRaw['paid'] ?? 0) + ($funnelRaw['confirmed'] ?? 0) + ($funnelRaw['approved'] ?? 0),
            'shipping' => ($funnelRaw['ready_to_ship'] ?? 0) + ($funnelRaw['shipped'] ?? 0),
            'delivered' => $funnelRaw['delivered'] ?? 0,
            'cancelled' => $funnelRaw['cancelled'] ?? 0,
        ];

        // 5. Vendas por Canal (Marketplace) — só roda se orders.integration_id
        // existir; pedidos importados via Magazord/Netshoes (CSV/planilha, sem
        // Integration OAuth) nunca têm esse vínculo, então não é obrigatório.
        $channelStats = $hasIntegrationId
            ? DB::table('orders')
                ->join('integrations', 'orders.integration_id', '=', 'integrations.id')
                ->where('orders.company_id', $cid)
                ->whereBetween("orders.{$dateCol}", [$start, $end])
                ->select('integrations.platform', DB::raw('SUM(orders.total_amount) as total'), DB::raw('COUNT(*) as qty'))
                ->groupBy('integrations.platform')
                ->get()
            : collect([]);

        // 6. Top Produtos (Curva A)
        $topProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $cid)
            ->whereBetween("orders.{$dateCol}", [$start, $end])
            ->where('orders.status', '!=', 'cancelled')
            ->select('order_items.title', 'order_items.sku', DB::raw('SUM(order_items.quantity) as qty'), DB::raw('SUM(order_items.unit_price * order_items.quantity) as total'))
            ->groupBy('order_items.sku', 'order_items.title')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        // 7. Produtos sem Estoque (Risco)
        $noStock = Product::where('company_id', $cid)
            ->where('stock_quantity', '<=', 0)
            ->limit(10)
            ->get();

        return Inertia::render('Reports/Index', [
            'range' => $range,
            'label' => $label,
            'start' => $start,
            'end' => $end,
            'currentStats' => $currentStats,
            'growth' => $growth,
            'chartLabels' => $chartLabels,
            'chartValues' => $chartValues,
            'funnel' => $funnel,
            'channelStats' => $channelStats,
            'topProducts' => $topProducts,
            'noStock' => $noStock
        ]);
    }

    // API para Exportação JSON (Consumida pelo Excel no Frontend)
    public function exportData(Request $request)
    {
        $user = Auth::user();
        $cid = $user->company_id;

        $orderCols = Schema::getColumnListing('orders');
        $hasOrderCol = fn ($c) => in_array($c, $orderCols, true);
        $dateCol = $hasOrderCol('date_created') ? 'date_created' : ($hasOrderCol('order_date') ? 'order_date' : 'created_at');

        // Pega os últimos 2000 pedidos para gerar o relatório
        $data = Order::where('company_id', $cid)
            ->with(['items', 'integration'])
            ->latest($dateCol)
            ->limit(2000)
            ->get()
            ->map(function ($order) use ($dateCol) {
            $rawDate = $order->{$dateCol};
            $date = $rawDate ? ($rawDate instanceof Carbon ? $rawDate : Carbon::parse($rawDate)) : null;
            return [
            'Data' => $date ? $date->format('d/m/Y H:i') : null,
            'ID Externo' => $order->external_id,
            'Canal' => $order->integration->platform ?? 'Manual',
            'Cliente' => $order->customer_name,
            'Status' => $order->status,
            'Total (R$)' => $order->total_amount,
            'Custo Prod (R$)' => $order->cost_products,
            'Taxas (R$)' => $order->cost_tax_platform,
            'Lucro (R$)' => $order->net_profit,
            // orders.items é uma coluna JSON legada (nunca escrita) que colide com o
            // relacionamento items() — $order->items (propriedade) sempre retorna
            // essa coluna (null), não a coleção de OrderItem. getRelation() pega o
            // que foi carregado no with(['items']) acima, sem cair nessa colisão.
            'Produtos' => $order->getRelation('items')->pluck('title')->join(', ')
            ];
        });

        return response()->json($data);
    }
}