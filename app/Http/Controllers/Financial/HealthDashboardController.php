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
        $companyId = auth()->user()->company_id;

        [$year, $month, $explicit] = $this->resolvePeriod($request);

        // Calcula indicadores reais (Rateio Dinâmico)
        $indicators = $this->financialService->calculateNetProfit($companyId, $year, $month);

        // Mesmo bug do Painel CFO (ver FinancialDashboardController): sem
        // filtro explícito, o DRE só olhava o mês corrente — nos primeiros
        // dias de qualquer mês, sem pedido confirmado ainda importado, a
        // tela inteira aparecia zerada mesmo com meses anteriores cheios de
        // dado real. Sem filtro do usuário e sem pedido no mês corrente,
        // busca o último mês (até 12 pra trás) que realmente tem pedido, e
        // avisa isso na tela (nunca troca o período em silêncio).
        $autoFallback = false;
        if (!$explicit && ($indicators['order_count'] ?? 0) === 0) {
            $cursor = Carbon::createFromDate($year, $month, 1);
            for ($i = 1; $i <= 12; $i++) {
                $cursor = $cursor->copy()->subMonthNoOverflow();
                $candidate = $this->financialService->calculateNetProfit($companyId, (int) $cursor->format('Y'), (int) $cursor->format('m'));
                if (($candidate['order_count'] ?? 0) > 0) {
                    $indicators = $candidate;
                    $year = (int) $cursor->format('Y');
                    $month = (int) $cursor->format('m');
                    $autoFallback = true;
                    break;
                }
            }
        }

        // Histórico para comparação (Últimos 6 meses, ancorado no mês exibido —
        // não em "agora"). subMonthsNoOverflow() evita o bug de subMonths() rolar
        // pro mês seguinte quando o dia atual não existe no mês de destino (ex.:
        // dia 31 -> "-1 mês" vira 1º do mês seguinte em vez do mês anterior).
        $anchor = Carbon::createFromDate($year, $month, 1);
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $anchor->copy()->subMonthsNoOverflow($i);
            $history[] = $this->financialService->calculateNetProfit($companyId, (int) $date->format('Y'), (int) $date->format('m'));
        }

        return Inertia::render('Financial/DreDashboard', [
            'indicators' => $indicators,
            'history' => $history,
            'filters' => [
                'month' => $month,
                'year' => $year,
            ],
            'autoFallback' => $autoFallback,
        ]);
    }

    /**
     * Lê `month`/`year` da querystring. Só considera "explícito" quando os
     * dois vêm válidos (mês 1-12, ano numérico) — formato inválido ou
     * ausente cai pro mês corrente, igual ao Painel CFO.
     */
    private function resolvePeriod(Request $request): array
    {
        $month = $request->query('month');
        $year = $request->query('year');

        if (is_numeric($month) && is_numeric($year) && (int) $month >= 1 && (int) $month <= 12 && (int) $year >= 2000) {
            return [(int) $year, (int) $month, true];
        }

        return [(int) date('Y'), (int) date('m'), false];
    }
}
