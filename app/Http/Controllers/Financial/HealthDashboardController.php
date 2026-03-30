<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use App\Services\Financial\FinancialProrationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Carbon\Carbon;

/**
 * Controller Superior para o DRE Executivo.
 * Utiliza o FinancialProrationService para entregar indicadores mastigados.
 */
class HealthDashboardController extends Controller
{
    private $financialService;

    public function __construct(FinancialProrationService $financialService)
    {
        $this->financialService = $financialService;
    }

    /**
     * Renderiza o DRE via Inertia.
     */
    public function dre(Request $request)
    {
        $month = $request->get('month', date('m'));
        $year = $request->get('year', date('Y'));

        $companyId = auth()->user()->company_id;

        // Calcula indicadores reais (Rateio Dinâmico)
        $indicators = $this->financialService->calculateNetProfit(
            $companyId,
            (int)$year,
            (int)$month
        );

        // Histórico para comparação (Últimos 6 meses)
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i)->format('m');
            $y = Carbon::now()->subMonths($i)->format('Y');
            $history[] = $this->financialService->calculateNetProfit($companyId, (int)$y, (int)$m);
        }

        return Inertia::render('Financial/DreDashboard', [
            'indicators' => $indicators,
            'history' => $history,
            'filters' => [
                'month' => $month,
                'year' => $year
            ]
        ]);
    }
}
