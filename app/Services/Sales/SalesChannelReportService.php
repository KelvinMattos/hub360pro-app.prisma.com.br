<?php

namespace App\Services\Sales;

use App\Models\ChannelSalesGoal;
use App\Models\SalesChannelAccount;
use App\Support\SalesChannels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Calcula as 4 visões que o cliente pedia na planilha manual "GERAL":
 * mensal (com comparativo ano a ano), semanal, diário e a granularidade de
 * conta Matriz/Filial do Mercado Livre.
 *
 * Pedido do cliente (05/08/2026): "os relatórios que são importados [por
 * canal] possuem as colunas de data de criação do pedido, status, data de
 * pagamento — não há lógica em importar manualmente uma informação que
 * outras planilhas já informaram". Antes desta mudança, a única fonte era o
 * upload manual do "Diário de Vendas" gravado em `channel_sales_daily`
 * (SalesChannelDailyImportService) — agora a fonte primária é `orders`,
 * já populada automaticamente pelos importadores nativos por canal
 * (OrderChannelImportController: Mercado Livre/Shopee/Centauro/Renner/
 * Magazine Luiza) e pelos importadores de Vendas do Magazord/Netshoes.
 *
 * Regra de combinação, por (canal, dia): se existe pelo menos 1 pedido
 * importado naquele dia/canal, ele SEMPRE vence — nunca mistura com o
 * manual pra não contar a mesma venda duas vezes. O upload manual só
 * preenche dias/canais em que ainda não existe nenhum pedido importado
 * (ex.: canais sem importador nativo ainda — Site, Amazon, Dafiti, Casas
 * Bahia, Shop Coopera — ver CLAUDE.md §8). Isso evita apagar o histórico
 * desses canais enquanto não há de onde importar os pedidos deles.
 *
 * Pedidos cujo `selling_channel`/conta não bate com nenhum canal
 * reconhecido (App\Support\SalesChannels::fromFreeText) caem no balde
 * "outros" — nunca somem da tela (CLAUDE.md §2.1: nunca falhar em
 * silêncio), o cliente vê e sinaliza se algum canal novo precisa de mapa.
 *
 * Comparação ano a ano só aparece quando o ano anterior tem dado de fato;
 * do contrário fica `null` (nunca fabrica um "0%" — CLAUDE.md §2.2).
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

        $daily = $this->unifiedDaily($companyId, $start, $end);

        $byChannelYearMonth = [];
        foreach ($daily as $row) {
            $date = Carbon::parse($row['sale_date']);
            $ch = $row['channel'];
            $y = $date->year;
            $m = $date->month;
            $existing = $byChannelYearMonth[$ch][$y][$m] ?? null;
            $byChannelYearMonth[$ch][$y][$m] = $existing ? $this->addMetrics($existing, $row) : $this->metricsOnly($row);
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

        $rows = array_values($this->unifiedDaily($companyId, $start, $end));

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
            $date = Carbon::parse($r['sale_date'])->startOfDay();
            foreach ($weekRanges as $i => $range) {
                if ($date->betweenIncluded($range['start']->copy()->startOfDay(), $range['end']->copy()->startOfDay())) {
                    if (isset($weeks[$i]['channels'][$r['channel']])) {
                        $weeks[$i]['channels'][$r['channel']]['value'] += (float) $r['paid_value'];
                        $weeks[$i]['channels'][$r['channel']]['orders'] += (int) $r['orders_count'];
                    }
                    $weeks[$i]['total']['value'] += (float) $r['paid_value'];
                    $weeks[$i]['total']['orders'] += (int) $r['orders_count'];
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
        $rows = $this->unifiedDaily($companyId, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay());

        $out = [];
        foreach ($rows as $r) {
            if ($channel && $r['channel'] !== $channel) {
                continue;
            }
            $out[] = [
                'channel' => $r['channel'],
                'channel_label' => SalesChannels::label($r['channel']),
                'sale_date' => $r['sale_date'],
                'gross_value' => (float) $r['gross_value'],
                'paid_value' => (float) $r['paid_value'],
                'canceled_value' => (float) $r['canceled_value'],
                'effectiveness_rate' => $r['gross_value'] > 0 ? round($r['paid_value'] / $r['gross_value'], 4) : null,
                'fees' => (float) $r['fees'],
                'shipping_cost' => (float) $r['shipping_cost'],
                'net_value' => (float) $r['net_value'],
                'orders_count' => (int) $r['orders_count'],
            ];
        }

        usort($out, fn ($a, $b) => [$a['sale_date'], $a['channel']] <=> [$b['sale_date'], $b['channel']]);

        return $out;
    }

    /* ================= CONTA MERCADO LIVRE (Matriz x Filial) ================= */

    public function mercadoLivreAccounts(int $companyId, int $year): array
    {
        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $daily = $this->unifiedDaily($companyId, $start, $end);

        $byAccountMonth = [];
        foreach ($daily as $row) {
            if (!in_array($row['channel'], SalesChannels::MERCADO_LIVRE_GROUP, true)) {
                continue;
            }
            $m = Carbon::parse($row['sale_date'])->month;
            $existing = $byAccountMonth[$row['channel']][$m] ?? null;
            $byAccountMonth[$row['channel']][$m] = $existing ? $this->addMetrics($existing, $row) : $this->metricsOnly($row);
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

    /**
     * Série diária por canal no intervalo, combinando as duas fontes: pedidos
     * importados (`orders`, prioridade) e o upload manual do Diário de Vendas
     * (`channel_sales_daily`, só preenche o que não tem pedido). Chave:
     * "{canal}|{Y-m-d}" — no máximo 1 linha por canal/dia, nunca soma as
     * duas fontes juntas (evitaria contar a mesma venda 2x).
     *
     * @return array<string, array{channel:string, sale_date:string, gross_value:float, paid_value:float, canceled_value:float, fees:float, shipping_cost:float, net_value:float, orders_count:int}>
     */
    private function unifiedDaily(int $companyId, Carbon $start, Carbon $end): array
    {
        $out = $this->ordersDerivedDaily($companyId, $start, $end);

        foreach ($this->manualDaily($companyId, $start, $end) as $key => $row) {
            if (!isset($out[$key])) {
                $out[$key] = $row;
            }
        }

        return $out;
    }

    /** Agrega `orders` por dia/canal — fonte automática (pedido do cliente 05/08/2026). */
    private function ordersDerivedDaily(int $companyId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('orders')) {
            return [];
        }
        $cols = Schema::getColumnListing('orders');
        if (!in_array('total_amount', $cols, true)) {
            return [];
        }

        $dateCol = collect(['date_created', 'order_date', 'created_at'])->first(fn ($c) => in_array($c, $cols, true));
        if (!$dateCol) {
            return [];
        }

        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        $channelCol = in_array('selling_channel', $cols, true) ? 'selling_channel' : null;
        $accountCol = in_array('sales_channel_account_id', $cols, true) ? 'sales_channel_account_id' : null;
        $feeCol = in_array('marketplace_fee', $cols, true) ? 'marketplace_fee' : null;
        $shipCol = in_array('shipping_cost', $cols, true) ? 'shipping_cost' : null;

        $q = DB::table('orders')
            ->whereRaw("DATE($dateCol) BETWEEN ? AND ?", [$start->format('Y-m-d'), $end->format('Y-m-d')]);
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }

        $channelExpr = $channelCol ?: 'NULL';
        $accountExpr = $accountCol ?: 'NULL';
        $statusExpr = $statusCol ?: 'NULL';

        $q->select([
            DB::raw("DATE($dateCol) as d"),
            DB::raw("$channelExpr as raw_channel"),
            DB::raw("$accountExpr as account_id"),
            DB::raw("$statusExpr as ord_status"),
            DB::raw('SUM(total_amount) as total'),
            $feeCol ? DB::raw("SUM($feeCol) as fee") : DB::raw('0 as fee'),
            $shipCol ? DB::raw("SUM($shipCol) as ship") : DB::raw('0 as ship'),
            DB::raw('COUNT(*) as cnt'),
        ])->groupBy(
            DB::raw("DATE($dateCol)"),
            DB::raw($channelExpr),
            DB::raw($accountExpr),
            DB::raw($statusExpr)
        );

        $rows = $q->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $accountsById = $accountCol
            ? SalesChannelAccount::where('company_id', $companyId)->get(['id', 'channel', 'label'])->keyBy('id')
            : collect();

        $out = [];
        foreach ($rows as $r) {
            $canonical = $this->resolveChannelKey($r->raw_channel, $r->account_id !== null ? (int) $r->account_id : null, $accountsById)
                ?? 'outros';
            $date = (string) $r->d;
            $key = $canonical . '|' . $date;

            $bucket = $out[$key] ?? $this->emptyMetricsRow($canonical, $date);
            $bucket['gross_value'] += (float) $r->total;

            if ($statusCol && mb_strtolower((string) $r->ord_status) === 'cancelled') {
                $bucket['canceled_value'] += (float) $r->total;
            } else {
                $bucket['fees'] += (float) $r->fee;
                $bucket['shipping_cost'] += (float) $r->ship;
                $bucket['orders_count'] += (int) $r->cnt;
            }

            $out[$key] = $bucket;
        }

        foreach ($out as $key => &$row) {
            $row['paid_value'] = $row['gross_value'] - $row['canceled_value'];
            $row['net_value'] = $row['paid_value'] - $row['fees'] - $row['shipping_cost'];
        }
        unset($row);

        return $out;
    }

    /**
     * Resolve o canal canônico de um pedido, priorizando a conta cadastrada
     * (`sales_channel_accounts.channel`, escolhida pelo usuário na importação
     * — fonte mais confiável) sobre o texto livre de `selling_channel`. Pra
     * Mercado Livre, tenta ainda distinguir Matriz/Filial pelo rótulo da
     * conta; sem isso, cai no balde genérico `mercado_livre`.
     */
    private function resolveChannelKey(?string $rawChannel, ?int $accountId, \Illuminate\Support\Collection $accountsById): ?string
    {
        if ($accountId !== null && $accountsById->has($accountId)) {
            $account = $accountsById->get($accountId);

            if ($account->channel === 'mercado_livre') {
                $byLabel = SalesChannels::fromFreeText($account->label);
                if (in_array($byLabel, ['mercado_livre_matriz', 'mercado_livre_filial'], true)) {
                    return $byLabel;
                }

                return 'mercado_livre';
            }

            $direct = match ($account->channel) {
                'shopee' => 'shopee',
                'centauro' => 'centauro',
                'renner' => 'renner',
                'magalu' => 'magalu',
                default => null,
            };
            if ($direct !== null) {
                return $direct;
            }
        }

        return SalesChannels::fromFreeText($rawChannel);
    }

    /** Fallback: dias/canais do Diário de Vendas importado manualmente sem nenhum pedido correspondente em `orders`. */
    private function manualDaily(int $companyId, Carbon $start, Carbon $end): array
    {
        if (!Schema::hasTable('channel_sales_daily')) {
            return [];
        }

        $rows = DB::table('channel_sales_daily')
            ->where('company_id', $companyId)
            ->whereBetween('sale_date', [$start->format('Y-m-d'), $end->format('Y-m-d')])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $date = Carbon::parse($r->sale_date)->format('Y-m-d');
            $out[$r->channel . '|' . $date] = [
                'channel' => $r->channel,
                'sale_date' => $date,
                'gross_value' => (float) $r->gross_value,
                'paid_value' => (float) $r->paid_value,
                'canceled_value' => (float) $r->canceled_value,
                'fees' => (float) $r->fees,
                'shipping_cost' => (float) $r->shipping_cost,
                'net_value' => (float) $r->net_value,
                'orders_count' => (int) $r->orders_count,
            ];
        }

        return $out;
    }

    private function emptyMetricsRow(string $channel, string $date): array
    {
        return [
            'channel' => $channel, 'sale_date' => $date,
            'gross_value' => 0.0, 'paid_value' => 0.0, 'canceled_value' => 0.0,
            'fees' => 0.0, 'shipping_cost' => 0.0, 'net_value' => 0.0, 'orders_count' => 0,
        ];
    }

    /** Descarta as chaves de identidade (channel/sale_date), deixando só as métricas somáveis. */
    private function metricsOnly(array $row): array
    {
        return array_intersect_key($row, array_flip(self::METRICS));
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
