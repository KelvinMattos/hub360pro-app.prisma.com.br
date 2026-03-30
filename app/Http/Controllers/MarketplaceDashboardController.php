<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Carbon\Carbon;

class MarketplaceDashboardController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;
        
        $today = Carbon::today();

        // Basic KPIs
        $ordersTodayQuery = Order::where('company_id', $companyId)
            ->whereDate('created_at', $today)
            ->whereIn('status', ['paid', 'shipped', 'delivered', 'accredited']);

        $salesToday = (float) $ordersTodayQuery->sum('total_amount');
        $ordersToday = $ordersTodayQuery->count();
        
        // Lucro Hoje (Fórmula Simplificada baseada em Custos Reais por Pedido)
        $costToday = (float) $ordersTodayQuery->sum(DB::raw('cost_products + cost_fee_commission + cost_fee_fixed + cost_fee_shipping + cost_fee_ads + cost_fee_taxes + cost_tax_platform'));
        $profitToday = $salesToday - $costToday;

        $activeListings = Product::where('company_id', $companyId)
            ->where('status', 'active')
            ->count();

        $pendingQuestions = \App\Models\MarketplaceQuestion::where('company_id', $companyId)
            ->where('status', 'unanswered')
            ->count();

        // Contas & Reputação
        $accounts = \App\Models\Integration::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        // Performance Chart (Last 7 Days)
        $chartData = collect(range(6, 0))->map(function($days) use ($companyId) {
            $date = Carbon::today()->subDays($days);
            return [
                'date' => $date->format('d/m'),
                'total' => (float) Order::where('company_id', $companyId)
                    ->whereDate('created_at', $date)
                    ->sum('total_amount'),
            ];
        });

        return Inertia::render('Marketplace/Dashboard', [
            'stats' => [
                'sales_today' => $salesToday,
                'profit_today' => round($profitToday, 2),
                'orders_today' => $ordersToday,
                'active_listings' => $activeListings,
                'pending_questions' => $pendingQuestions,
                'growth_percent' => 12.5,
            ],
            'accounts' => $accounts,
            'chart_data' => $chartData
        ]);
    }
}
