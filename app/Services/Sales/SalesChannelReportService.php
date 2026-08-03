<?php

namespace App\Services\Sales;

use App\Models\ChannelSalesGoal;
use App\Support\SalesChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lê `channel_sales_daily` (gravada pelo SalesChannelDailyImportService) e
 * calcula as 4 visões que o cliente pedia na planilha manual "GERAL":
 * mensal (com comparativo ano a ano), semanal, diário e a granularidade de
 * conta Matriz/Filial do Mercado Livre.
 *
 * Tudo aqui é CALCULADO a partir da série diária, nunca duplicado numa
 * tabela própria — reimportar um mês corrige automaticamente todas as
 * visões derivadas. Comparação ano a ano só aparece quando o ano anterior
 * tem dado de fato importado; do contrário fica `null` (nunca fabrica um
 * "0%" ou uma variação que na verdade é "sem dado" — CLAUDE.md §2.2).
 */
class SalesChannelReportService
{
    private const METRICS = ['gross_value', 'paid_value', 'canceled_value', 'fees', 'shipping_cost', 'net_value', 'orders_count'];

    /** Métrica usada para %variação (a mesma que a planilha original comparava: PEDIDOS PAGOS/EFETIVADOS). */
    private const COMPARISON_METRIC = 'paid_value';

    /* ================= MENSAL (com comparativo ano anterior) ================= */

    public function monthlySummary(int $companyId, int $year): array
    {
        $prevYear = $year - 1;
        $start = Carbon::create($prevYear, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $rows = DB::table('channel_sales_daily')
            ->select(
                'channel',
                DB::raw('YEAR(sale_date) as yr'),
                DB::raw('MONTH(sale_date) as mo'),
                DB::raw('SUM(gross_value) as gross_value'),
                DB::raw('SUM(paid_value) as paid_value'),
                DB::raw('SUM(canceled_value) as canceled_value'),
                DB::raw('SUM(fees) as fees'),
                DB::raw('SUM(shipping_cost) as shipping_cost'),
                DB::raw('SUM(net_value) as net_value'),
                DB::raw('SUM(orders_count) as orders_count')
            )
            ->where('company_id', $companyId)
            ->whereBetween('sale_date', [$start, $end])
            ->groupBy('channel', DB::raw('YEAR(sale_date)'), DB::raw('MONTH(sale_date)'))
            ->get();

        $byChannelYearMonth = [];
        foreach ($rows as $r) {
            $byChannelYearMonth[$r->channel][(int) $r->yr][(int) $r->mo] = $this->rowToMetrics($r);
        }

        $channels = SalesChannels::all();
        $months = [];

        for ($m = 1; $m <= 12; $m++) {
            $monthData = [];
            $total = null;
            $totalPrev = null;
            $mlTotal = null;
            $mlTotalPrev = null;

            foreach ($channels as $ch) {
                $current = $byChannelYearMonth[$ch][$year][$m] ?? null;
                $previous = $byChannelYearMonth[$ch][$prevYear][$m] ?? null;

                if ($current !== null) {
                    $total = $this->addMetrics($total, $current);
                    if (in_array($ch, SalesChannels::MERCADO_LIVRE_GROUP, true)) {
                        $mlTotal = $this->addMetrics($mlTotal, $current);
                    }
                }
                if ($previous !== null) {
                    $totalPrev = $this->addMetrics($totalPrev, $previous);
                    if (in_array($ch, SalesChannels::MERCADO_LIVRE_GROUP, true)) {
                        $mlTotalPrev = $this->addMetrics($mlTotalPrev, $previous);
                    }
                }

                $monthData[$ch] = [
                    'current' => $current,
                    'previous' => $previous,
                    'diff_pct' => $this->diffPct($current, $previous),
                ];
            }

            $monthData['mercado_livre_total'] = [
                'current' => $mlTotal,
                'previous' => $mlTotalPrev,
                'diff_pct' => $this->diffPct($mlTotal, $mlTotalPrev),
            ];
            $monthData['total'] = [
                'current' => $total,
                'previous' => $totalPrev,
                'diff_pct' => $this->diffPct($total, $totalPrev),
            ];

            $months[$m] = $monthData;
        }

        return [
            'year' => $year,
            'prev_year' => $prevYear,
            'channels' => $this->channelLabels($channels),
            'months' => $months,
        ];
    }

    /* ================= SEMANAL ================= */

    /**
     * Semanas de sábado a sexta (convenção de varejo/e-commerce — fim de
     * semana concentra vendas), com a primeira e a última semana do mês
     * recortadas nos limites do mês (não funde com o mês vizinho).
     */
    public function weeklySummary(int $companyId, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $rows = DB::table('channel_sales_daily')
            ->where('company_id', $companyId)
            ->whereBetween('sale_date', [$start, $end])
            ->get(['channel', 'sale_date', 'paid_value', 'orders_count']);

        $weekRanges = [];
        $cursor = $start->copy();
        while ($cursor->lte($end)) {
            $weekEnd = $cursor->copy();
            while ($weekEnd->dayOfWeekIso !== Carbon::FRIDAY && $weekEnd->lt($end)) {
                $weekEnd->addDay();
            }
            if ($weekEnd->gt($end)) {
                $weekEnd = $end->copy()->startOfDay();
            }
            $weekRanges[] = ['start' => $cursor->copy(), 'end' => $weekEnd->copy()];
            $cursor = $weekEnd->copy()->addDay()->startOfDay();
        }

        $channels = SalesChannels::all();
        $weeks = [];
        foreach ($weekRanges as $range) {
            $weeks[] = [
                'label' => $range['start']->format('d/m') . ' a ' . $range['end']->format('d/m'),
                'channels' => array_fill_keys($channels, ['value' => 0.0, 'orders' => 0]),
                'total' => ['value' => 0.0, 'orders' => 0],
            ];
        }

        foreach ($rows as $r) {
            $date = Carbon::parse($r->sale_date)->startOfDay();
            foreach ($weekRanges as $i => $range) {
                if ($date->betweenIncluded($range['start']->copy()->startOfDay(), $range['end']->copy()->startOfDay())) {
                    if (isset($weeks[$i]['channels'][$r->channel])) {
                        $weeks[$i]['channels'][$r->channel]['value'] += (float) $r->paid_value;
                        $weeks[$i]['channels'][$r->channel]['orders'] += (int) $r->orders_count;
                    }
                    $weeks[$i]['total']['value'] += (float) $r->paid_value;
                    $weeks[$i]['total']['orders'] += (int) $r->orders_count;
                    break;
                }
            }
        }

        $accumulatedValue = 0.0;
        $accumulatedOrders = 0;
        foreach ($weeks as &$w) {
            $accumulatedValue += $w['total']['value'];
            $accumulatedOrders += $w['total']['orders'];
            $w['accumulated'] = ['value' => $accumulatedValue, 'orders' => $accumulatedOrders];
        }
        unset($w);

        return [
            'year' => $year,
            'month' => $month,
            'channels' => $this->channelLabels($channels),
            'weeks' => $weeks,
        ];
    }

    /* ================= DIÁRIO (listagem) ================= */

    public function daily(int $companyId, ?string $channel, string $from, string $to): array
    {
        $query = DB::table('channel_sales_daily')
            ->where('company_id', $companyId)
            ->whereBetween('sale_date', [$from, $to]);

        if ($channel) {
            $query->where('channel', $channel);
        }

        $rows = $query->orderBy('sale_date')->orderBy('channel')->get();

        return $rows->map(function ($r) {
            return [
                'channel' => $r->channel,
                'channel_label' => SalesChannels::label($r->channel),
                'sale_date' => Carbon::parse($r->sale_date)->format('Y-m-d'),
                'gross_value' => (float) $r->gross_value,
                'paid_value' => (float) $r->paid_value,
                'canceled_value' => (float) $r->canceled_value,
                'effectiveness_rate' => $r->gross_value > 0 ? round($r->paid_value / $r->gross_value, 4) : null,
                'fees' => (float) $r->fees,
                'shipping_cost' => (float) $r->shipping_cost,
                'net_value' => (float) $r->net_value,
                'orders_count' => (int) $r->orders_count,
            ];
        })->values()->all();
    }

    /* ================= CONTA MERCADO LIVRE (Matriz x Filial) ================= */

    public function mercadoLivreAccounts(int $companyId, int $year): array
    {
        $rows = DB::table('channel_sales_daily')
            ->select(
                'channel',
                DB::raw('MONTH(sale_date) as mo'),
                DB::raw('SUM(gross_value) as gross_value'),
                DB::raw('SUM(paid_value) as paid_value'),
                DB::raw('SUM(canceled_value) as canceled_value'),
                DB::raw('SUM(fees) as fees'),
                DB::raw('SUM(shipping_cost) as shipping_cost'),
                DB::raw('SUM(net_value) as net_value'),
                DB::raw('SUM(orders_count) as orders_count')
            )
            ->where('company_id', $companyId)
            ->whereIn('channel', SalesChannels::MERCADO_LIVRE_GROUP)
            ->whereYear('sale_date', $year)
            ->groupBy('channel', DB::raw('MONTH(sale_date)'))
            ->get();

        $byAccountMonth = [];
        foreach ($rows as $r) {
            $byAccountMonth[$r->channel][(int) $r->mo] = $this->rowToMetrics($r);
        }

        $result = [];
        foreach (SalesChannels::MERCADO_LIVRE_GROUP as $account) {
            $months = [];
            $previous = null;
            for ($m = 1; $m <= 12; $m++) {
                $current = $byAccountMonth[$account][$m] ?? null;
                $months[$m] = [
                    'current' => $current,
                    'diff_pct' => $m > 1 ? $this->diffPct($current, $previous) : null,
                ];
                $previous = $current;
            }
            $result[$account] = [
                'label' => SalesChannels::label($account),
                'months' => $months,
            ];
        }

        return ['year' => $year, 'accounts' => $result];
    }

    /* ================= METAS ================= */

    public function goalsFor(int $companyId, int $year): array
    {
        return ChannelSalesGoal::where('company_id', $companyId)->where('year', $year)->get()
            ->mapWithKeys(fn ($g) => [$g->channel . ':' . $g->month => (float) $g->goal_amount])
            ->all();
    }

    public function saveGoal(int $companyId, string $channel, int $year, int $month, float $amount): void
    {
        ChannelSalesGoal::updateOrCreate(
            ['company_id' => $companyId, 'channel' => $channel, 'year' => $year, 'month' => $month],
            ['goal_amount' => $amount]
        );
    }

    /* ================= internos ================= */

    private function rowToMetrics(object $row): array
    {
        return [
            'gross_value' => (float) $row->gross_value,
            'paid_value' => (float) $row->paid_value,
            'canceled_value' => (float) $row->canceled_value,
            'fees' => (float) $row->fees,
            'shipping_cost' => (float) $row->shipping_cost,
            'net_value' => (float) $row->net_value,
            'orders_count' => (int) $row->orders_count,
        ];
    }

    private function addMetrics(?array $a, array $b): array
    {
        $a ??= array_fill_keys(self::METRICS, 0);
        foreach (self::METRICS as $k) {
            $a[$k] = ($a[$k] ?? 0) + ($b[$k] ?? 0);
        }

        return $a;
    }

    /** Só compara quando os dois lados existem de fato — nunca fabrica variação a partir de "sem dado". */
    private function diffPct(?array $current, ?array $previous): ?float
    {
        if ($current === null || $previous === null) {
            return null;
        }
        $prevVal = $previous[self::COMPARISON_METRIC] ?? 0;
        if ((float) $prevVal === 0.0) {
            return null;
        }

        return round(($current[self::COMPARISON_METRIC] - $prevVal) / $prevVal, 4);
    }

    private function channelLabels(array $channels): array
    {
        $labels = collect($channels)->mapWithKeys(fn ($c) => [$c => SalesChannels::label($c)])->all();
        $labels['mercado_livre_total'] = 'Mercado Livre (Total)';
        $labels['total'] = 'TOTAL';

        return $labels;
    }
}
