<?php

namespace App\Services;

use App\Models\DecisionCycle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor do ciclo de decisão: testa UMA mudança de preço uniforme
 * (`limits.price_change_pct`) de forma gradual sobre um escopo de SKUs,
 * com:
 *
 *  - Simulação prévia (sem gravar nada) usando margem real e volume recente
 *    — assume volume CONSTANTE porque não existe modelo de elasticidade
 *    real no sistema (CLAUDE.md marca isso como trabalho futuro). É um
 *    piso conservador, não uma previsão.
 *  - Execução gradual: `applyNextBatch()` aplica um lote por vez, sempre
 *    respeitando o piso (PricingEngine::floorPrice) — nunca aplica abaixo dele.
 *  - Grupo de controle: uma amostra sistemática do próprio escopo é reservada
 *    (não recebe a mudança) para medir o ROI real por
 *    DIFERENÇAS-EM-DIFERENÇAS, isolando tendências de mercado que afetam
 *    os dois grupos igualmente.
 *  - Freio automático: aborta o ciclo se algum produto já aplicado ficar
 *    abaixo do piso ATUAL (custo pode ter mudado) ou se o volume do grupo
 *    de tratamento cair além do limite configurado.
 */
class DecisionCycleEngine
{
    public function __construct(
        private PricingEngine $pricingEngine,
        private ChannelConfigService $channelConfig,
        private RepricingEngine $repricingEngine,
    ) {
    }

    /**
     * `products` NÃO tem `selling_channel` no schema real (só `orders` tem) — inclui a coluna
     * na seleção apenas se ela existir, mesmo padrão defensivo do RepricingEngine.
     */
    private function productSelectColumns(array $base): array
    {
        if (Schema::hasColumn('products', 'selling_channel')) {
            $base[] = 'selling_channel';
        }
        return $base;
    }

    /** Resolve os produtos do escopo: product_ids | brand | pricing_role (sku_strategy), combináveis. */
    public function resolveScopeProductIds(int $companyId, array $scope): array
    {
        if (!Schema::hasTable('products')) {
            return [];
        }

        $q = DB::table('products')->where('company_id', $companyId);

        if (!empty($scope['product_ids'])) {
            $q->whereIn('id', $scope['product_ids']);
        }
        if (!empty($scope['brand'])) {
            $q->where('brand', $scope['brand']);
        }
        if (!empty($scope['pricing_role']) && Schema::hasTable('sku_strategy')) {
            $ids = DB::table('sku_strategy')
                ->where('company_id', $companyId)
                ->where('pricing_role', $scope['pricing_role'])
                ->pluck('product_id');
            $q->whereIn('id', $ids);
        }

        return $q->pluck('id')->all();
    }

    /**
     * Projeta o impacto da mudança de preço ANTES de aplicar qualquer coisa.
     * Assume volume constante (últimos 30 dias) — deixa isso explícito no resultado.
     */
    public function simulate(DecisionCycle $cycle): array
    {
        $productIds = $this->resolveScopeProductIds($cycle->company_id, $cycle->scope);
        $priceChangePct = (float) ($cycle->limits['price_change_pct'] ?? 0);
        $minMarginPct = (float) ($cycle->limits['min_margin_pct'] ?? 10);
        $durationDays = max(1, (int) $cycle->duration_days);

        $channelData = $this->channelCharges($cycle->company_id);
        $margins = $this->repricingEngine->brandMargins($cycle->company_id);
        $volumes = $this->recentVolume($cycle->company_id, $productIds, 30);

        $rows = empty($productIds) ? collect() : DB::table('products')->whereIn('id', $productIds)
            ->select($this->productSelectColumns(['id', 'sku', 'title', 'brand', 'cost_price', 'sale_price', 'promotional_price', 'price']))
            ->get();

        $blockedCount = 0;
        $totalEstimatedGain = 0.0;
        $items = [];

        foreach ($rows as $r) {
            $current = $this->effectivePrice($r);
            $cost = (float) ($r->cost_price ?? 0);
            $brandMargin = $this->brandMarginFor($margins, $r->brand ?? null, $minMarginPct);
            $encargos = $this->resolveEncargos($channelData, $r->selling_channel ?? null);
            $floor = $cost > 0 ? $this->pricingEngine->floorPrice($cost, $encargos, $brandMargin) : null;

            $proposed = round($current * (1 + $priceChangePct / 100), 2);
            $blocked = $floor !== null && $proposed < $floor;
            $finalPrice = $blocked ? $current : $proposed;
            if ($blocked) $blockedCount++;

            $currentMargin = $cost > 0 ? $this->pricingEngine->unitContribution($current, $cost, $encargos) : null;
            $projectedMargin = $cost > 0 ? $this->pricingEngine->unitContribution($finalPrice, $cost, $encargos) : null;

            $volume30 = $volumes[$r->id] ?? 0;
            $expectedVolume = $volume30 * ($durationDays / 30);
            $rowGain = ($currentMargin !== null && $projectedMargin !== null)
                ? round(($projectedMargin - $currentMargin) * $expectedVolume, 2)
                : null;
            if ($rowGain !== null) $totalEstimatedGain += $rowGain;

            $items[] = [
                'product_id' => $r->id,
                'sku' => $r->sku,
                'title' => $r->title,
                'current_price' => round($current, 2),
                'proposed_price' => $finalPrice,
                'blocked_by_floor' => $blocked,
                'floor' => $floor,
                'current_margin' => $currentMargin,
                'projected_margin' => $projectedMargin,
                'volume_30d' => $volume30,
                'estimated_gain_rs' => $rowGain,
            ];
        }

        $result = [
            'assumption' => 'Estimativa assume volume constante (ritmo dos últimos 30 dias) — não há modelo de elasticidade real no sistema. É um piso conservador, não uma previsão de IA.',
            'scope_count' => count($productIds),
            'blocked_by_floor' => $blockedCount,
            'estimated_gain_rs' => round($totalEstimatedGain, 2),
            'items' => $items,
        ];

        $cycle->simulation_result = $result;
        $cycle->estimated_gain = round($totalEstimatedGain, 2);
        $cycle->status = DecisionCycle::STATUS_SIMULATED;
        $cycle->save();

        return $result;
    }

    /** Inicia o ciclo: separa tratamento/controle e captura a linha de base (30 dias antes de agora). */
    public function start(DecisionCycle $cycle): DecisionCycle
    {
        if (!in_array($cycle->status, [DecisionCycle::STATUS_DRAFT, DecisionCycle::STATUS_SIMULATED], true)) {
            throw new \RuntimeException("Ciclo não pode iniciar a partir do status '{$cycle->status}'.");
        }

        $productIds = $this->resolveScopeProductIds($cycle->company_id, $cycle->scope);
        if (empty($productIds)) {
            throw new \RuntimeException('Escopo vazio — nenhum produto encontrado para este ciclo.');
        }

        sort($productIds);
        $total = count($productIds);
        $controlPct = (float) ($cycle->limits['control_pct'] ?? 20);

        // control_pct=0 tem que resultar em ZERO controle (não forçar 1) — e com um único
        // produto no escopo não há como separar grupo de controle sem zerar o tratamento.
        $controlCount = 0;
        if ($controlPct > 0 && $total > 1) {
            $controlCount = max(1, (int) round($total * $controlPct / 100));
            $controlCount = min($controlCount, $total - 1); // sempre sobra pelo menos 1 no tratamento
        }

        // Amostragem sistemática (1 a cada N), não só os primeiros/últimos IDs.
        $step = max(1, (int) floor(count($productIds) / max(1, $controlCount)));
        $controlIds = [];
        foreach ($productIds as $i => $id) {
            if ($i % $step === 0 && count($controlIds) < $controlCount) {
                $controlIds[] = $id;
            }
        }
        $treatmentIds = array_values(array_diff($productIds, $controlIds));

        $now = Carbon::now();
        $baselineDays = 30;
        $baseline = [
            'treatment' => $this->groupMetrics($cycle->company_id, $treatmentIds, $now->copy()->subDays($baselineDays), $now),
            'control' => $this->groupMetrics($cycle->company_id, $controlIds, $now->copy()->subDays($baselineDays), $now),
            'days' => $baselineDays,
        ];

        $cycle->treatment_product_ids = $treatmentIds;
        $cycle->control_product_ids = $controlIds;
        $cycle->applied_product_ids = [];
        $cycle->baseline_snapshot = $baseline;
        $cycle->status = DecisionCycle::STATUS_RUNNING;
        $cycle->started_at = $now;
        $cycle->ended_at = null;
        $cycle->abort_reason = null;
        $cycle->save();

        return $cycle;
    }

    /** Aplica o próximo lote do grupo de tratamento, respeitando o piso. Nunca mexe no grupo de controle. */
    public function applyNextBatch(DecisionCycle $cycle, ?int $batchSize = null): array
    {
        if ($cycle->status !== DecisionCycle::STATUS_RUNNING) {
            throw new \RuntimeException("Ciclo precisa estar 'running' para aplicar lotes (está '{$cycle->status}').");
        }

        $batchSize = $batchSize ?? (int) ($cycle->limits['batch_size'] ?? 10);
        $applied = $cycle->applied_product_ids ?? [];
        $pending = array_values(array_diff($cycle->treatment_product_ids ?? [], $applied));
        $batch = array_slice($pending, 0, max(1, $batchSize));

        if (empty($batch)) {
            return ['applied' => 0, 'blocked' => 0, 'done' => true];
        }

        $priceChangePct = (float) ($cycle->limits['price_change_pct'] ?? 0);
        $minMarginPct = (float) ($cycle->limits['min_margin_pct'] ?? 10);
        $channelData = $this->channelCharges($cycle->company_id);
        $margins = $this->repricingEngine->brandMargins($cycle->company_id);
        $priceCol = Schema::hasColumn('products', 'promotional_price') ? 'promotional_price'
            : (Schema::hasColumn('products', 'sale_price') ? 'sale_price' : null);

        $rows = DB::table('products')->whereIn('id', $batch)
            ->select($this->productSelectColumns(['id', 'brand', 'cost_price', 'sale_price', 'promotional_price', 'price']))
            ->get();

        $appliedCount = 0;
        $blockedCount = 0;
        $logs = [];
        $now = now();

        foreach ($rows as $r) {
            $current = $this->effectivePrice($r);
            $cost = (float) ($r->cost_price ?? 0);
            $brandMargin = $this->brandMarginFor($margins, $r->brand ?? null, $minMarginPct);
            $encargos = $this->resolveEncargos($channelData, $r->selling_channel ?? null);
            $floor = $cost > 0 ? $this->pricingEngine->floorPrice($cost, $encargos, $brandMargin) : null;
            $proposed = round($current * (1 + $priceChangePct / 100), 2);

            if ($floor !== null && $proposed < $floor) {
                $logs[] = [
                    'decision_cycle_id' => $cycle->id, 'product_id' => $r->id,
                    'price_before' => $current, 'price_after' => null,
                    'action' => 'blocked', 'reason' => sprintf('Abaixo do piso (R$ %.2f).', $floor),
                    'created_at' => $now, 'updated_at' => $now,
                ];
                $blockedCount++;
                continue;
            }

            if ($priceCol) {
                DB::table('products')->where('id', $r->id)->update([$priceCol => $proposed]);
            }
            $logs[] = [
                'decision_cycle_id' => $cycle->id, 'product_id' => $r->id,
                'price_before' => $current, 'price_after' => $proposed,
                'action' => 'applied', 'reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
            $appliedCount++;
        }

        if (!empty($logs)) {
            DB::table('decision_cycle_logs')->insert($logs);
        }

        $newApplied = array_values(array_unique(array_merge($applied, $batch)));
        $cycle->applied_product_ids = $newApplied;
        $cycle->save();

        return [
            'applied' => $appliedCount,
            'blocked' => $blockedCount,
            'done' => count($newApplied) >= count($cycle->treatment_product_ids ?? []),
        ];
    }

    /** Verifica as duas condições de freio; aborta o ciclo (grava o motivo) se alguma disparar. */
    public function checkCircuitBreaker(DecisionCycle $cycle): bool
    {
        if ($cycle->status !== DecisionCycle::STATUS_RUNNING) {
            return false;
        }

        $applied = $cycle->applied_product_ids ?? [];
        if (!empty($applied)) {
            $channelData = $this->channelCharges($cycle->company_id);
            $margins = $this->repricingEngine->brandMargins($cycle->company_id);
            $minMarginPct = (float) ($cycle->limits['min_margin_pct'] ?? 10);

            $rows = DB::table('products')->whereIn('id', $applied)
                ->select($this->productSelectColumns(['id', 'brand', 'cost_price', 'sale_price', 'promotional_price', 'price']))
                ->get();

            foreach ($rows as $r) {
                $cost = (float) ($r->cost_price ?? 0);
                if ($cost <= 0) continue;
                $brandMargin = $this->brandMarginFor($margins, $r->brand ?? null, $minMarginPct);
                $encargos = $this->resolveEncargos($channelData, $r->selling_channel ?? null);
                $floor = $this->pricingEngine->floorPrice($cost, $encargos, $brandMargin);
                $current = $this->effectivePrice($r);

                if ($floor !== null && $current < $floor) {
                    $this->abort($cycle, sprintf('Violação de piso: produto #%d abaixo de R$ %.2f.', $r->id, $floor));
                    return true;
                }
            }
        }

        $maxDropPct = (float) ($cycle->limits['max_volume_drop_pct'] ?? 30);
        $baseline = $cycle->baseline_snapshot['treatment'] ?? null;
        $baselineDays = $cycle->baseline_snapshot['days'] ?? 30;
        $afterDays = $cycle->started_at ? max(1, abs(Carbon::now()->diffInDays($cycle->started_at))) : 0;

        // Período de carência: nos primeiros dias após iniciar, "0 vendas ainda" é esperado
        // (não deu tempo de vender), não uma queda real — checar isso cedo demais abortaria
        // todo ciclo na hora de iniciar.
        if ($baseline && ($baseline['volume'] ?? 0) > 0 && $afterDays >= 3) {
            $after = $this->groupMetrics($cycle->company_id, $cycle->treatment_product_ids ?? [], $cycle->started_at, Carbon::now());

            $baselineDailyVolume = $baseline['volume'] / max(1, $baselineDays);
            $afterDailyVolume = $after['volume'] / $afterDays;
            $dropPct = (($baselineDailyVolume - $afterDailyVolume) / $baselineDailyVolume) * 100;

            if ($dropPct > $maxDropPct) {
                $this->abort($cycle, sprintf('Queda de volume de %.1f%% (limite %.1f%%).', $dropPct, $maxDropPct));
                return true;
            }
        }

        return false;
    }

    /** ROI real em R$ por diferenças-em-diferenças: isola o efeito do preço da tendência geral do mercado. */
    public function measureRoi(DecisionCycle $cycle): ?array
    {
        if (!$cycle->started_at || !$cycle->baseline_snapshot) {
            return null;
        }

        $baseline = $cycle->baseline_snapshot;
        $baselineDays = $baseline['days'] ?? 30;
        if ($baselineDays <= 0) {
            return null;
        }

        $afterDays = max(1, abs(Carbon::now()->diffInDays($cycle->started_at)));
        $afterTreatment = $this->groupMetrics($cycle->company_id, $cycle->treatment_product_ids ?? [], $cycle->started_at, Carbon::now());
        $afterControl = $this->groupMetrics($cycle->company_id, $cycle->control_product_ids ?? [], $cycle->started_at, Carbon::now());

        $roiRevenue = $this->diffInDiffTotal(
            $baseline['treatment']['revenue'] ?? 0, $afterTreatment['revenue'],
            $baseline['control']['revenue'] ?? 0, $afterControl['revenue'],
            $baselineDays, $afterDays
        );
        $roiProfit = $this->diffInDiffTotal(
            $baseline['treatment']['profit'] ?? 0, $afterTreatment['profit'],
            $baseline['control']['profit'] ?? 0, $afterControl['profit'],
            $baselineDays, $afterDays
        );

        $result = [
            'method' => 'diferenças-em-diferenças (grupo de controle retirado do próprio escopo)',
            'after_days' => $afterDays,
            'baseline_days' => $baselineDays,
            'roi_revenue_rs' => $roiRevenue,
            'roi_profit_rs' => $roiProfit,
            'treatment' => ['before' => $baseline['treatment'], 'after' => $afterTreatment],
            'control' => ['before' => $baseline['control'], 'after' => $afterControl],
        ];

        $cycle->roi_result = $result;
        $cycle->save();

        return $result;
    }

    /**
     * DiD contrafactual em R$ totais no período "after".
     *
     * NÃO usa a diferença de deltas ABSOLUTOS — isso viesa o resultado quando
     * tratamento e controle têm tamanhos diferentes (o padrão aqui, já que o
     * controle é uma fração do escopo): a mesma tendência de mercado em % vira
     * um delta em R$ maior no grupo maior, e seria contado como "efeito do
     * preço" sem ser. Em vez disso, aplica a taxa de crescimento % do CONTROLE
     * sobre a própria base do tratamento para estimar o contrafactual ("o que
     * o tratamento teria feito só seguindo a tendência geral") e compara com
     * o que de fato aconteceu.
     */
    public function diffInDiffTotal(float $treatmentBefore, float $treatmentAfter, float $controlBefore, float $controlAfter, int $baselineDays, int $afterDays): float
    {
        $treatmentBeforeDaily = $treatmentBefore / max(1, $baselineDays);
        $treatmentAfterDaily = $treatmentAfter / max(1, $afterDays);
        $controlBeforeDaily = $controlBefore / max(1, $baselineDays);
        $controlAfterDaily = $controlAfter / max(1, $afterDays);

        $controlGrowthPct = $controlBeforeDaily > 0
            ? ($controlAfterDaily - $controlBeforeDaily) / $controlBeforeDaily
            : ($controlAfterDaily > 0 ? 1.0 : 0.0);

        $expectedTreatmentAfterDaily = $treatmentBeforeDaily * (1 + $controlGrowthPct);
        $roiDaily = $treatmentAfterDaily - $expectedTreatmentAfterDaily;

        return round($roiDaily * $afterDays, 2);
    }

    /** Um "tick" do ciclo: freio -> próximo lote -> conclusão. Pensado para rodar 1x/dia via scheduler. */
    public function tick(DecisionCycle $cycle): array
    {
        if ($cycle->status !== DecisionCycle::STATUS_RUNNING) {
            return ['action' => 'skipped', 'reason' => "status={$cycle->status}"];
        }

        if ($this->checkCircuitBreaker($cycle)) {
            return ['action' => 'aborted', 'reason' => $cycle->abort_reason];
        }

        $applied = count($cycle->applied_product_ids ?? []);
        $total = count($cycle->treatment_product_ids ?? []);
        if ($applied < $total) {
            return ['action' => 'batch_applied'] + $this->applyNextBatch($cycle);
        }

        if ($cycle->started_at && Carbon::now()->greaterThanOrEqualTo($cycle->started_at->copy()->addDays($cycle->duration_days))) {
            $roi = $this->measureRoi($cycle);
            $cycle->status = DecisionCycle::STATUS_COMPLETED;
            $cycle->ended_at = Carbon::now();
            $cycle->save();
            return ['action' => 'completed', 'roi' => $roi];
        }

        return ['action' => 'waiting'];
    }

    private function abort(DecisionCycle $cycle, string $reason): void
    {
        $cycle->status = DecisionCycle::STATUS_ABORTED;
        $cycle->abort_reason = $reason;
        $cycle->ended_at = Carbon::now();
        $cycle->save();
    }

    private function brandMarginFor(array $margins, ?string $brand, float $fallback): float
    {
        if ($brand && isset($margins[mb_strtolower(trim($brand))])) {
            return $margins[mb_strtolower(trim($brand))];
        }
        return $fallback;
    }

    private function effectivePrice(object $r): float
    {
        foreach (['promotional_price', 'sale_price', 'price'] as $c) {
            if (!empty($r->$c ?? null)) {
                return (float) $r->$c;
            }
        }
        return 0.0;
    }

    /** Mesma resolução de encargos do RepricingEngine (comissão do canal + imposto global). */
    private function channelCharges(int $companyId): array
    {
        $config = $this->channelConfig->forCompany($companyId);
        $imposto = (float) ($config['imposto'] ?? 0);

        $comissoes = [];
        foreach ($config['channels'] ?? [] as $ch) {
            $key = mb_strtolower(trim((string) ($ch['id'] ?? '')));
            $label = mb_strtolower(trim((string) ($ch['label'] ?? '')));
            $comissao = (float) ($ch['comissao'] ?? 0);
            if ($key !== '') $comissoes[$key] = $comissao;
            if ($label !== '') $comissoes[$label] = $comissao;
        }

        return ['imposto' => $imposto, 'comissoes' => $comissoes];
    }

    private function resolveEncargos(array $channelData, ?string $sellingChannel): float
    {
        $comissoes = $channelData['comissoes'];
        $key = mb_strtolower(trim((string) $sellingChannel));

        $comissao = $comissoes[$key]
            ?? $comissoes['netshoes']
            ?? (!empty($comissoes) ? array_sum($comissoes) / count($comissoes) : 0.0);

        return $comissao + $channelData['imposto'];
    }

    /** Volume vendido por produto nos últimos N dias, usando a data REAL do pedido (CLAUDE.md §5.1). */
    private function recentVolume(int $companyId, array $productIds, int $days): array
    {
        $result = array_fill_keys($productIds, 0);
        if (empty($productIds) || !Schema::hasTable('order_items') || !Schema::hasTable('orders')) {
            return $result;
        }

        $dateCol = $this->resolveOrderDateColumn();
        if ($dateCol === null) {
            return $result;
        }

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('order_items.product_id', $productIds)
            ->where("orders.$dateCol", '>=', Carbon::now()->subDays($days))
            ->select('order_items.product_id', DB::raw('SUM(order_items.quantity) as qty'))
            ->groupBy('order_items.product_id')
            ->get();

        foreach ($rows as $r) {
            $result[$r->product_id] = (int) $r->qty;
        }

        return $result;
    }

    /** Receita/lucro/volume reais de um grupo de produtos num intervalo, usando a data REAL do pedido. */
    private function groupMetrics(int $companyId, array $productIds, Carbon $from, Carbon $to): array
    {
        $empty = ['revenue' => 0.0, 'profit' => 0.0, 'volume' => 0];
        if (empty($productIds) || !Schema::hasTable('order_items') || !Schema::hasTable('orders')) {
            return $empty;
        }

        $dateCol = $this->resolveOrderDateColumn();
        if ($dateCol === null) {
            return $empty;
        }

        $row = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('order_items.product_id', $productIds)
            ->whereBetween("orders.$dateCol", [$from, $to])
            ->selectRaw('SUM(order_items.unit_price * order_items.quantity) as revenue,
                         SUM((order_items.unit_price - order_items.unit_cost) * order_items.quantity) as profit,
                         SUM(order_items.quantity) as volume')
            ->first();

        return [
            'revenue' => round((float) ($row->revenue ?? 0), 2),
            'profit' => round((float) ($row->profit ?? 0), 2),
            'volume' => (int) ($row->volume ?? 0),
        ];
    }

    private function resolveOrderDateColumn(): ?string
    {
        if (!Schema::hasTable('orders')) {
            return null;
        }
        $cols = Schema::getColumnListing('orders');
        return in_array('date_created', $cols, true) ? 'date_created'
            : (in_array('order_date', $cols, true) ? 'order_date'
            : (in_array('created_at', $cols, true) ? 'created_at' : null));
    }
}
