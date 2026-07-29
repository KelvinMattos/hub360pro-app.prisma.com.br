<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Order;
use App\Services\Financial\FinancialProrationService;
use Illuminate\Support\Facades\Auth;

class FinancialDashboardController extends Controller
{
    protected $financialService;

    public function __construct(FinancialProrationService $financialService)
    {
        $this->financialService = $financialService;
    }

    public function index()
    {
        $user = Auth::user();
        $companyId = $user->company_id;
        $month = (int)date('m');
        $year = (int)date('Y');

        // Estatísticas Financeiras Reais (DRE Inteligente)
        $rawStats = $this->financialService->calculateNetProfit($companyId, $year, $month);
        $grossRevenue = $rawStats['gross_revenue'] ?? 0;
        // 'contribution_margin' do serviço vem em R$ (receita - custos variáveis), não em %.
        // O card "Margem Contr." exibe percentual — precisa dividir pela receita, com guarda de zero.
        $contributionMarginPct = $grossRevenue > 0
            ? round((($rawStats['contribution_margin'] ?? 0) / $grossRevenue) * 100, 1)
            : 0;

        $stats = [
            'grossRevenue' => $grossRevenue,
            'realRevenue' => $grossRevenue, // Ajustar conforme lógica de faturamento real se disponível
            'fixedExpenses' => $rawStats['fixed_costs'] ?? 0,
            'netProfit' => $rawStats['net_profit'] ?? 0,
            'contributionMargin' => $contributionMarginPct,
            'orderCount' => $rawStats['order_count'] ?? 0
        ];

        // Histórico de Faturamento (Últimos 6 meses). history[5] é o mês atual,
        // history[4] o mês anterior — usados abaixo para crescimento e margem reais.
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('m', strtotime("-$i month"));
            $y = date('Y', strtotime("-$i month"));
            $hStats = $this->financialService->calculateNetProfit($companyId, (int)$y, (int)$m);
            $revenue = $hStats['gross_revenue'] ?? 0;
            $profit = $hStats['net_profit'] ?? 0;
            $contribMargin = $hStats['contribution_margin'] ?? 0;
            $history[] = [
                'month' => date('M', strtotime("-$i month")),
                'revenue' => $revenue,
                'profit' => $profit,
                // Mesma base do card "Margem Contr." (contribution_margin / receita), não margem líquida.
                'marginPct' => $revenue > 0 ? round($contribMargin / $revenue * 100, 1) : null,
            ];
        }

        // Crescimento real de receita vs mês anterior (nunca fabricado; null se não houver base).
        $previousRevenue = $history[4]['revenue'] ?? 0;
        $revenueGrowthPct = $previousRevenue > 0
            ? round((($stats['grossRevenue'] - $previousRevenue) / $previousRevenue) * 100, 1)
            : null;

        // Margem comparada à própria média histórica da empresa (não existe fonte real de
        // "média do setor" — comparar contra si mesma é o dado honesto disponível).
        $pastMargins = array_values(array_filter(
            array_map(fn ($h) => $h['marginPct'], array_slice($history, 0, 5)),
            fn ($v) => $v !== null
        ));
        $avgHistoricalMarginPct = count($pastMargins) > 0 ? array_sum($pastMargins) / count($pastMargins) : null;
        $marginDeltaVsHistoryPct = $avgHistoricalMarginPct !== null
            ? round($stats['contributionMargin'] - $avgHistoricalMarginPct, 1)
            : null;

        return Inertia::render('Financial/Dashboard', [
            'stats' => $stats,
            'history' => $history,
            'revenueGrowthPct' => $revenueGrowthPct,
            'marginDeltaVsHistoryPct' => $marginDeltaVsHistoryPct,
            'companyName' => $user->company->name ?? 'Prisma Client'
        ]);
    }

    public function dre()
    {
        // Redireciona para a view existente se houver, ou implementa lógica similar
        return Inertia::render('Financial/DRE', [
            // Dados para o DRE
        ]);
    }
}
