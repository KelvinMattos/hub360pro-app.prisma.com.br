<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Order;
use App\Models\PurchaseOrder;
use Carbon\Carbon;

class FinancialReportService
{
    /**
     * Gera os dados para a DRE (Demonstração de Resultado do Exercício)
     */
    public function generateDRE($startDate, $endDate)
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        // Receita Bruta (Pedidos pagos no período)
        $grossRevenue = Order::whereBetween('date_created', [$start, $end])
            ->where('status', 'paid')
            ->sum('total_amount');

        // Deduções (Impostos estimados nas notas autorizadas)
        $totalTaxes = \App\Models\Invoice::whereBetween('issued_at', [$start, $end])
            ->where('status', 'authorized')
            ->sum('tax_amount');

        // Receita Líquida
        $netRevenue = $grossRevenue - $totalTaxes;

        // CPV - Custo dos Produtos Vendidos
        $costOfGoodsSold = Order::whereBetween('date_created', [$start, $end])
            ->where('status', 'paid')
            ->sum('cost_products');

        // Lucro Bruto
        $grossProfit = $netRevenue - $costOfGoodsSold;

        // Despesas Operacionais (Transações de 'expense' pagas)
        $operatingExpenses = Transaction::whereBetween('payment_date', [$start, $end])
            ->where('type', 'expense')
            ->where('status', 'paid')
            ->sum('amount');

        // Resultado Líquido
        $netIncome = $grossProfit - $operatingExpenses;

        return [
            'gross_revenue' => $grossRevenue,
            'taxes' => $totalTaxes,
            'net_revenue' => $netRevenue,
            'cpv' => $costOfGoodsSold,
            'gross_profit' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'net_income' => $netIncome,
            'margin_percent' => $netRevenue > 0 ? ($netIncome / $netRevenue) * 100 : 0,
        ];
    }
}