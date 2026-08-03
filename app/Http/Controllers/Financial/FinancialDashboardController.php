<?php

namespace App\Http\Controllers\Financial;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Order;
use App\Services\Financial\FinancialProrationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Carbon;

class FinancialDashboardController extends Controller
{
    protected $financialService;

    public function __construct(FinancialProrationService $financialService)
    {
        $this->financialService = $financialService;
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        $companyId = $user->company_id;

        [$year, $month, $explicit] = $this->resolvePeriod($request);

        // Estatísticas Financeiras Reais (DRE Inteligente)
        $rawStats = $this->financialService->calculateNetProfit($companyId, $year, $month);

        // Incidente (03/08/2026): o painel só olhava o mês corrente, sem filtro
        // nenhum. Nos primeiros dias de qualquer mês (poucos pedidos ainda
        // importados/confirmados), a tela inteira aparecia zerada — parecendo
        // quebrada — mesmo com meses anteriores cheios de dado real. Sem
        // filtro explícito do usuário e sem pedido nenhum no mês corrente,
        // busca o último mês (até 12 pra trás) que realmente tem pedido, e
        // avisa na tela que houve esse ajuste (nunca troca o período em
        // silêncio — ver CLAUDE.md §2.1).
        $autoFallback = false;
        if (!$explicit && ($rawStats['order_count'] ?? 0) === 0) {
            $cursor = Carbon::createFromDate($year, $month, 1);
            for ($i = 1; $i <= 12; $i++) {
                $cursor = $cursor->copy()->subMonthNoOverflow();
                $candidate = $this->financialService->calculateNetProfit($companyId, (int) $cursor->format('Y'), (int) $cursor->format('m'));
                if (($candidate['order_count'] ?? 0) > 0) {
                    $rawStats = $candidate;
                    $year = (int) $cursor->format('Y');
                    $month = (int) $cursor->format('m');
                    $autoFallback = true;
                    break;
                }
            }
        }

        $grossRevenue = $rawStats['gross_revenue'] ?? 0;
        // 'contribution_margin' do serviço vem em R$ (receita - custos variáveis), não em %.
        // O card "Margem Contr." exibe percentual — precisa dividir pela receita, com guarda de zero.
        $contributionMarginPct = $grossRevenue > 0
            ? round((($rawStats['contribution_margin'] ?? 0) / $grossRevenue) * 100, 1)
            : 0;

        $stats = [
            'grossRevenue' => $grossRevenue,
            'fixedExpenses' => $rawStats['fixed_costs'] ?? 0,
            'netProfit' => $rawStats['net_profit'] ?? 0,
            'contributionMargin' => $contributionMarginPct,
            'orderCount' => $rawStats['order_count'] ?? 0,
            'breakEvenRevenue' => $rawStats['break_even_revenue'] ?? null,
        ];

        // Histórico de Faturamento (Últimos 6 meses, ancorado no mês exibido —
        // não em "agora" — pra navegar pra um mês passado continuar mostrando
        // os 6 meses que terminam nele, e não sempre os mesmos 6 meses reais).
        // history[5] é o mês exibido, history[4] o anterior — usados abaixo
        // para crescimento e margem reais.
        //
        // Incidente: usava strtotime("-$i month"), que estoura em qualquer dia do
        // mês que não existe no mês de destino (ex.: rodando dia 31, "-1 month"
        // vira "31 de junho" -> PHP rola pra 1º de julho, ou seja, o "mês anterior"
        // virava o MESMO mês atual). subMonthsNoOverflow() trava no último dia
        // válido do mês de destino em vez de rolar pro mês seguinte.
        $anchor = Carbon::createFromDate($year, $month, 1);
        $history = [];
        for ($i = 5; $i >= 0; $i--) {
            $date = $anchor->copy()->subMonthsNoOverflow($i);
            $hStats = $this->financialService->calculateNetProfit($companyId, (int) $date->format('Y'), (int) $date->format('m'));
            $revenue = $hStats['gross_revenue'] ?? 0;
            $profit = $hStats['net_profit'] ?? 0;
            $contribMargin = $hStats['contribution_margin'] ?? 0;
            $history[] = [
                'month' => $date->format('M'),
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
            'companyName' => $user->company->name ?? 'Prisma Client',
            'period' => [
                'month' => sprintf('%04d-%02d', $year, $month),
                'label' => $rawStats['period']['label'] ?? null,
            ],
            'autoFallback' => $autoFallback,
        ]);
    }

    /**
     * Lê `?month=Y-m` da querystring. Formato inválido ou ausente cai pro mês
     * corrente — mas o 3º valor do retorno (`$explicit`) diz se foi o usuário
     * quem escolheu, pra distinguir de um fallback automático por falta de dado.
     */
    private function resolvePeriod(Request $request): array
    {
        $monthParam = $request->query('month');
        if (is_string($monthParam) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $monthParam, $m)) {
            return [(int) $m[1], (int) $m[2], true];
        }

        return [(int) date('Y'), (int) date('m'), false];
    }
}
