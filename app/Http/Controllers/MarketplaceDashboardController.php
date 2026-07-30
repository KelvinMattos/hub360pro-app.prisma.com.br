<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Carbon\Carbon;

class MarketplaceDashboardController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;

        $today = Carbon::today();
        $yesterday = Carbon::yesterday();
        $dateCol = $this->orderDateColumn();

        // Basic KPIs
        $ordersTodayQuery = Order::where('company_id', $companyId)
            ->whereDate($dateCol, $today)
            ->whereIn('status', Order::CONFIRMED_STATUSES);

        $salesToday = (float) $ordersTodayQuery->sum('total_amount');
        $ordersToday = $ordersTodayQuery->count();

        // Lucro Hoje (Fórmula Simplificada baseada em Custos Reais por Pedido)
        $costToday = (float) $ordersTodayQuery->sum(DB::raw('cost_products + cost_fee_commission + cost_fee_fixed + cost_fee_shipping + cost_fee_ads + cost_fee_taxes + cost_tax_platform'));
        $profitToday = $salesToday - $costToday;

        // Crescimento real: hoje vs ontem. Sem venda ontem para comparar, fica
        // null (a UI mostra "—", nunca um percentual inventado — CLAUDE.md §16).
        $salesYesterday = (float) Order::where('company_id', $companyId)
            ->whereDate($dateCol, $yesterday)
            ->whereIn('status', Order::CONFIRMED_STATUSES)
            ->sum('total_amount');
        $growthPercent = $salesYesterday > 0 ? round((($salesToday - $salesYesterday) / $salesYesterday) * 100, 1) : null;

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
        $chartData = collect(range(6, 0))->map(function ($days) use ($companyId, $dateCol) {
            $date = Carbon::today()->subDays($days);
            return [
                'date' => $date->format('d/m'),
                'total' => (float) Order::where('company_id', $companyId)
                    ->whereDate($dateCol, $date)
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
                'growth_percent' => $growthPercent,
            ],
            'accounts' => $accounts,
            'chart_data' => $chartData
        ]);
    }

    /**
     * date_created é a data real do pedido; created_at é o timestamp da importação
     * (ver CLAUDE.md §5.1) — usar created_at aqui contaria vendas antigas
     * reimportadas hoje como se tivessem acontecido hoje.
     */
    private function orderDateColumn(): string
    {
        $cols = Schema::getColumnListing('orders');
        $has = fn ($c) => in_array($c, $cols, true);

        return $has('date_created') ? 'date_created' : ($has('order_date') ? 'order_date' : 'created_at');
    }
}
