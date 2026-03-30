<?php

namespace App\Services\Financial;

use App\Models\Order;
use App\Models\FixedExpense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Service de Inteligência Financeira e Rateios.
 * Reconstruído para garantir a saúde do Dashboard e do DRE.
 */
class FinancialProrationService
{
    /**
     * Calcula o Lucro Líquido Real para uma empresa em um período específico.
     * Realiza o rateio dinâmico de custos fixos sobre o volume de vendas.
     */
    public function calculateNetProfit(int $companyId, int $year, int $month): array
    {
        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // 1. Métricas de Vendas (Baseado em Pedidos Pagos/Enviados)
        $ordersQuery = Order::where('company_id', $companyId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'shipped', 'delivered', 'accredited']);

        $orderCount = (clone $ordersQuery)->count();

        // Receita Bruta (Total Pago pelo Cliente)
        $grossRevenue = (clone $ordersQuery)->sum('total_amount') ?: 0;

        // Custos Variáveis Acumulados
        $costProducts = (clone $ordersQuery)->sum('cost_products') ?: 0;
        $costFees = (clone $ordersQuery)->sum(DB::raw('cost_fee_commission + cost_fee_fixed + cost_fee_shipping + cost_fee_ads')) ?: 0;
        
        $hasCostTaxCol = \Illuminate\Support\Facades\Schema::hasColumn('orders', 'cost_tax_platform');
        $taxColSql = $hasCostTaxCol ? 'cost_tax_platform' : '0';
        $costTaxes = (clone $ordersQuery)->sum(DB::raw("cost_fee_taxes + $taxColSql")) ?: 0;

        // Margem de Contribuição (Antes dos Custos Fixos)
        $contributionMargin = $grossRevenue - ($costProducts + $costFees + $costTaxes);

        // 2. Levantamento de Custos Fixos (Despesas do Mês)
        // Nota: Consolidado em FixedExpense conforme evolução do ERP
        $totalFixedCosts = FixedExpense::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount') ?: 0;

        // 3. Lucro Líquido Real
        $netProfit = $contributionMargin - $totalFixedCosts;

        // Percentual de Margem Líquida
        $marginPercent = $grossRevenue > 0 ? ($netProfit / $grossRevenue) * 100 : 0;

        return [
            'gross_revenue' => round($grossRevenue, 2),
            'cost_products' => round($costProducts, 2),
            'cost_fees' => round($costFees, 2),
            'cost_taxes' => round($costTaxes, 2),
            'contribution_margin' => round($contributionMargin, 2),
            'fixed_costs' => round($totalFixedCosts, 2),
            'net_profit' => round($netProfit, 2),
            'margin_percent' => round($marginPercent, 2),
            'order_count' => $orderCount,
            'period' => [
                'month' => $month,
                'year' => $year,
                'label' => $startDate->translatedFormat('F Y')
            ]
        ];
    }

    /**
     * Calcula o valor de custo fixo que deve ser absorvido por cada pedido no mês.
     */
    public function calculateAllocationPerOrder(int $companyId, $date): float
    {
        $carbonDate = ($date instanceof \Carbon\Carbon) ? $date : Carbon::parse($date);
        $year = $carbonDate->year;
        $month = $carbonDate->month;

        $startDate = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $endDate = $startDate->copy()->endOfMonth();

        // Total de Pedidos Faturáveis no mês
        $orderCount = Order::where('company_id', $companyId)
            ->whereBetween('created_at', [$startDate, $endDate])
            ->whereIn('status', ['paid', 'shipped', 'delivered', 'accredited'])
            ->count();

        if ($orderCount === 0) return 0;

        // Total de Custos Fixos no mês
        $totalFixedCosts = FixedExpense::where('company_id', $companyId)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->sum('amount') ?: 0;

        return round($totalFixedCosts / $orderCount, 2);
    }
}
