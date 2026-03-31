<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class MarketplaceAdsController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;
        $today = Carbon::today();

        // Métricas de Ads (Baseadas nos pedidos que tiveram custo de publicidade)
        $adsOrders = Order::where('company_id', $companyId)
            ->where('cost_fee_ads', '>', 0);

        $totalAdSpend = (float) $adsOrders->sum('cost_fee_ads');
        $totalAdSales = (float) $adsOrders->sum('total_amount');
        
        $roas = $totalAdSpend > 0 ? $totalAdSales / $totalAdSpend : 0;
        $acos = $totalAdSales > 0 ? ($totalAdSpend / $totalAdSales) * 100 : 0;

        // Histórico de Ads (7 dias)
        $chartData = collect(range(6, 0))->map(function($days) use ($companyId) {
            $date = Carbon::today()->subDays($days);
            $dayOrders = Order::where('company_id', $companyId)
                ->whereDate('created_at', $date)
                ->where('cost_fee_ads', '>', 0);

            return [
                'date' => $date->format('d/m'),
                'spend' => (float) $dayOrders->sum('cost_fee_ads'),
                'sales' => (float) $dayOrders->sum('total_amount'),
            ];
        });

        // Top Produtos em Ads (Join com Itens para pegar IDs externos corretos)
        $topAdsProducts = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)
            ->where('orders.cost_fee_ads', '>', 0)
            ->select(
                'order_items.external_item_id as product_external_id', 
                'order_items.title', 
                DB::raw('SUM(orders.total_amount) as sales'), 
                DB::raw('SUM(orders.cost_fee_ads) as spend')
            )
            ->groupBy('order_items.external_item_id', 'order_items.title')
            ->orderBy('sales', 'desc')
            ->take(10)
            ->get();

        return Inertia::render('Marketplace/Ads', [
            'metrics' => [
                'total_spend' => round($totalAdSpend, 2),
                'total_sales' => round($totalAdSales, 2),
                'roas' => round($roas, 2),
                'acos' => round($acos, 2),
                'ads_count' => $adsOrders->count(),
            ],
            'chart_data' => $chartData,
            'top_products' => $topAdsProducts
        ]);
    }
}
