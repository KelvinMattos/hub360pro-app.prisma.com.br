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

        $stats = [
            'grossRevenue' => $rawStats['gross_revenue'] ?? 0,
            'realRevenue' => $rawStats['gross_revenue'] ?? 0, // Ajustar conforme lógica de faturamento real se disponível
            'fixedExpenses' => $rawStats['fixed_costs'] ?? 0,
            'netProfit' => $rawStats['net_profit'] ?? 0,
            'contributionMargin' => $rawStats['contribution_margin'] ?? 0,
            'orderCount' => $rawStats['order_count'] ?? 0
        ];

        // Histórico de Faturamento (Últimos 6 meses)
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = date('m', strtotime("-$i month"));
            $y = date('Y', strtotime("-$i month"));
            $hStats = $this->financialService->calculateNetProfit($companyId, (int)$y, (int)$m);
            $history[] = [
                'month' => date('M', strtotime("-$i month")),
                'revenue' => $hStats['gross_revenue'] ?? 0,
                'profit' => $hStats['net_profit'] ?? 0
            ];
        }

        return Inertia::render('Financial/Dashboard', [
            'stats' => $stats,
            'history' => $history,
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
