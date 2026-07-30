<?php

namespace App\Services\Inventory;

use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de Reposição Inteligente (/inventory/planning).
 *
 * Roda 1x/dia via `inventory:compute-replenishment` — a tela só LÊ a tabela
 * `replenishment_plan`, paginada e filtrada no banco. Nunca mais calcula
 * dezenas de milhares de linhas na hora do request.
 *
 * Duas limitações reais de dado, documentadas em vez de escondidas
 * (CLAUDE.md §2.4 — nunca fingir precisão que o dado não sustenta):
 *
 *  1) `stock_movements` existe no schema mas NUNCA é escrita por nenhum
 *     importador — não há histórico real de "este SKU estava em estoque no
 *     dia X". A velocidade usa uma aproximação: quando o produto está
 *     zerado HOJE, os "dias ativos" da janela vão do início da janela até a
 *     ÚLTIMA venda dentro dela (span de venda), em vez do calendário cheio —
 *     assim um SKU que vendeu bem e só ficou sem estoque no fim do período
 *     não tem a velocidade artificialmente diluída (o erro clássico que o
 *     cliente descreveu). Quando o produto tem estoque hoje, assume-se a
 *     janela cheia — melhor aproximação disponível sem histórico real.
 *  2) `purchase_orders` não tem item por produto (só total do pedido) — não
 *     há como calcular "em trânsito" com dado real hoje. Fica sempre 0 na
 *     fórmula de quantidade sugerida, documentado, não inventado.
 */
class ReplenishmentEngine
{
    public const STATUS_RUPTURA = 'ruptura';
    public const STATUS_CRITICO = 'critico';
    public const STATUS_REPOR = 'repor';
    public const STATUS_SAUDAVEL = 'saudavel';
    public const STATUS_EXCESSO = 'excesso';
    public const STATUS_ESTOQUE_MORTO = 'estoque_morto';
    public const STATUS_DESCONTINUADO = 'descontinuado';

    private const URGENCY_WEIGHT = [
        self::STATUS_RUPTURA => 1000,
        self::STATUS_CRITICO => 500,
        self::STATUS_REPOR => 200,
        self::STATUS_ESTOQUE_MORTO => 100,
        self::STATUS_EXCESSO => 50,
        self::STATUS_SAUDAVEL => 0,
        self::STATUS_DESCONTINUADO => 0,
    ];

    private const ABC_MULTIPLIER = ['A' => 3, 'B' => 2, 'C' => 1];

    public static function statusLabels(): array
    {
        return [
            self::STATUS_RUPTURA => 'Ruptura',
            self::STATUS_CRITICO => 'Crítico',
            self::STATUS_REPOR => 'Repor',
            self::STATUS_SAUDAVEL => 'Saudável',
            self::STATUS_EXCESSO => 'Excesso',
            self::STATUS_ESTOQUE_MORTO => 'Estoque Morto',
            self::STATUS_DESCONTINUADO => 'Descontinuado',
        ];
    }

    /** Lê (ou cria com defaults) os parâmetros configuráveis da empresa. Nada hardcoded na tela. */
    public function settingsFor(int $companyId): object
    {
        $row = DB::table('replenishment_settings')->where('company_id', $companyId)->first();
        if ($row) {
            return $row;
        }

        $now = Carbon::now();
        DB::table('replenishment_settings')->insertOrIgnore([
            'company_id' => $companyId,
            'weight_v7' => 0.5,
            'weight_v30' => 0.3,
            'weight_v90' => 0.2,
            'target_coverage_days' => 30,
            'safety_days' => 7,
            'service_level_z' => 1.65,
            'excess_threshold_days' => 120,
            'dead_stock_days' => 90,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DB::table('replenishment_settings')->where('company_id', $companyId)->first();
    }

    /** Recalcula o plano de reposição inteiro da empresa e grava (upsert) em `replenishment_plan`. */
    public function computeCompany(int $companyId, ?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : Carbon::now();

        if (!Schema::hasTable('products') || !Schema::hasTable('replenishment_plan')) {
            return 0;
        }

        $settings = $this->settingsFor($companyId);

        $products = DB::table('products')
            ->where('company_id', $companyId)
            ->select(['id', 'sku', 'title', 'brand', 'stock_quantity', 'cost_price', 'sale_price',
                      'lead_time', 'safety_stock', 'moq', 'purchase_multiple', 'launched_at'])
            ->get();

        if ($products->isEmpty()) {
            return 0;
        }

        $productIds = $products->pluck('id')->all();
        $deadStockDays = (int) $settings->dead_stock_days;
        $sales = $this->salesByProduct($companyId, $productIds, $now, $deadStockDays);

        $revenueByProduct = [];
        foreach ($productIds as $id) {
            $revenueByProduct[$id] = $sales[$id]['revenue_30'];
        }
        $abcClasses = $this->abcClasses($revenueByProduct);

        $d7 = $now->copy()->subDays(7)->startOfDay();
        $d30 = $now->copy()->subDays(30)->startOfDay();
        $d90 = $now->copy()->subDays(90)->startOfDay();
        $dDead = $now->copy()->subDays($deadStockDays)->startOfDay();

        $rows = [];
        foreach ($products as $p) {
            $s = $sales[$p->id];
            $stock = (int) $p->stock_quantity;
            $leadTime = (int) ($p->lead_time ?: 15);
            $costPrice = (float) ($p->cost_price ?? 0);
            $salePrice = (float) ($p->sale_price ?? 0);
            $moq = (int) ($p->moq ?: 1);
            $multiple = (int) ($p->purchase_multiple ?: 1);

            $active7 = $this->activeDays(7, $stock, $s['last_sale_7'], $d7);
            $active30 = $this->activeDays(30, $stock, $s['last_sale_30'], $d30);
            $active90 = $this->activeDays(90, $stock, $s['last_sale_90'], $d90);

            $v7 = $active7 > 0 ? $s['qty_7'] / $active7 : 0.0;
            $v30 = $active30 > 0 ? $s['qty_30'] / $active30 : 0.0;
            $v90 = $active90 > 0 ? $s['qty_90'] / $active90 : 0.0;

            $weighted = $this->weightedVelocity(
                $v7, $v30, $v90,
                (float) $settings->weight_v7, (float) $settings->weight_v30, (float) $settings->weight_v90
            );

            $stddev = $this->demandStddev($s['daily'], $active30, $stock, $s['last_sale_30']);

            $safetyStock = $this->safetyStock(
                $stddev, $weighted, $leadTime, (int) $settings->safety_days, (float) $settings->service_level_z
            );
            $reorderPoint = $this->reorderPoint($weighted, $leadTime, $safetyStock);
            $coverageDays = $this->coverageDays($stock, $weighted);
            $suggestedQty = $this->suggestedQty(
                $weighted, $leadTime, (int) $settings->target_coverage_days, $safetyStock, $stock, $moq, $multiple
            );

            $hasSales = $s['qty_dead'] > 0;
            $isNew = !empty($p->launched_at) && Carbon::parse($p->launched_at)->gte($dDead);
            $status = $this->classifyStatus(
                $stock, $hasSales, $isNew, $coverageDays, $leadTime,
                (int) $settings->target_coverage_days, (int) $settings->excess_threshold_days
            );

            $abcClass = $abcClasses[$p->id] ?? null;
            $revenueAtRisk = $this->revenueAtRisk($coverageDays, $weighted, $salePrice);
            $immobilized = round($stock * $costPrice, 2);
            $priority = $this->priorityScore($status, $abcClass, $revenueAtRisk);

            $rows[] = [
                'company_id' => $companyId,
                'product_id' => $p->id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $p->brand,
                'stock' => $stock,
                'cost_price' => $costPrice,
                'sale_price' => $salePrice,
                'velocity_7' => round($v7, 3),
                'velocity_30' => round($v30, 3),
                'velocity_90' => round($v90, 3),
                'velocity_weighted' => round($weighted, 3),
                'demand_stddev' => $stddev,
                'lead_time_days' => $leadTime,
                'safety_stock' => $safetyStock,
                'reorder_point' => $reorderPoint,
                'coverage_days' => $coverageDays,
                'suggested_qty' => $suggestedQty,
                'status' => $status,
                'abc_class' => $abcClass,
                'revenue_30d' => round($s['revenue_30'], 2),
                'revenue_at_risk_30d' => $revenueAtRisk,
                'immobilized_value' => $immobilized,
                'priority_score' => $priority,
                'computed_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('replenishment_plan')->upsert(
                $chunk,
                ['company_id', 'product_id'],
                ['sku', 'title', 'brand', 'stock', 'cost_price', 'sale_price', 'velocity_7', 'velocity_30',
                 'velocity_90', 'velocity_weighted', 'demand_stddev', 'lead_time_days', 'safety_stock',
                 'reorder_point', 'coverage_days', 'suggested_qty', 'status', 'abc_class', 'revenue_30d',
                 'revenue_at_risk_30d', 'immobilized_value', 'priority_score', 'computed_at', 'updated_at']
            );
        }

        return count($rows);
    }

    /** Vendas confirmadas (Order::CONFIRMED_STATUSES) por produto, usando a data REAL do pedido (CLAUDE.md §5.1). */
    private function salesByProduct(int $companyId, array $productIds, Carbon $now, int $deadStockDays): array
    {
        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = [
                'qty_7' => 0, 'qty_30' => 0, 'qty_90' => 0, 'qty_dead' => 0,
                'daily' => [],
                'last_sale_7' => null, 'last_sale_30' => null, 'last_sale_90' => null,
                'revenue_30' => 0.0,
            ];
        }

        if (empty($productIds) || !Schema::hasTable('order_items') || !Schema::hasTable('orders')) {
            return $result;
        }

        $orderCols = Schema::getColumnListing('orders');
        $dateCol = in_array('date_created', $orderCols, true) ? 'date_created'
            : (in_array('order_date', $orderCols, true) ? 'order_date'
            : (in_array('created_at', $orderCols, true) ? 'created_at' : null));
        if ($dateCol === null || !in_array('company_id', $orderCols, true)) {
            return $result;
        }

        $maxWindow = max(90, $deadStockDays);
        $windowStart = $now->copy()->subDays($maxWindow)->startOfDay();
        $d7 = $now->copy()->subDays(7)->startOfDay();
        $d30 = $now->copy()->subDays(30)->startOfDay();
        $d90 = $now->copy()->subDays(90)->startOfDay();
        $dDead = $now->copy()->subDays($deadStockDays)->startOfDay();

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('order_items.product_id', $productIds)
            ->whereIn('orders.status', Order::CONFIRMED_STATUSES)
            ->where("orders.$dateCol", '>=', $windowStart)
            ->select('order_items.product_id', "orders.$dateCol as odate", 'order_items.quantity', 'order_items.unit_price')
            ->get();

        foreach ($rows as $r) {
            if (!isset($result[$r->product_id])) {
                continue;
            }
            $odate = Carbon::parse($r->odate);
            $qty = (int) $r->quantity;
            $bucket = &$result[$r->product_id];

            if ($odate->gte($dDead)) {
                $bucket['qty_dead'] += $qty;
            }
            if ($odate->gte($d90)) {
                $bucket['qty_90'] += $qty;
                $bucket['last_sale_90'] = $bucket['last_sale_90'] === null ? $odate : max($bucket['last_sale_90'], $odate);
            }
            if ($odate->gte($d30)) {
                $bucket['qty_30'] += $qty;
                $bucket['revenue_30'] += $qty * (float) $r->unit_price;
                $day = $odate->toDateString();
                $bucket['daily'][$day] = ($bucket['daily'][$day] ?? 0) + $qty;
                $bucket['last_sale_30'] = $bucket['last_sale_30'] === null ? $odate : max($bucket['last_sale_30'], $odate);
            }
            if ($odate->gte($d7)) {
                $bucket['qty_7'] += $qty;
                $bucket['last_sale_7'] = $bucket['last_sale_7'] === null ? $odate : max($bucket['last_sale_7'], $odate);
            }
            unset($bucket);
        }

        return $result;
    }

    /**
     * "Dias ativos" da janela — aproximação documentada na classe (sem
     * stock_movements real). Produto com estoque hoje usa a janela cheia;
     * produto zerado hoje usa o span até a última venda dentro da janela.
     */
    public function activeDays(int $windowDays, int $stock, ?Carbon $lastSale, Carbon $windowStart): int
    {
        if ($stock > 0 || $lastSale === null) {
            return $windowDays;
        }
        $days = (int) $windowStart->diffInDays($lastSale) + 1;
        return max(1, min($windowDays, $days));
    }

    /** velocidade ponderada = w7*v7 + w30*v30 + w90*v90 — mais peso na recente, por padrão. */
    public function weightedVelocity(float $v7, float $v30, float $v90, float $w7, float $w30, float $w90): float
    {
        return $w7 * $v7 + $w30 * $v30 + $w90 * $v90;
    }

    /** Desvio-padrão da demanda diária na janela de 30d, só sobre os dias "ativos" (aproximação, ver docblock da classe). */
    public function demandStddev(array $dailyMap, int $activeDays30, int $stock, ?Carbon $lastSale30): ?float
    {
        if ($activeDays30 < 2) {
            return null;
        }

        $endDate = ($stock <= 0 && $lastSale30 !== null) ? $lastSale30->copy() : Carbon::now();
        $values = [];
        for ($i = 0; $i < $activeDays30; $i++) {
            $day = $endDate->copy()->subDays($i)->toDateString();
            $values[] = $dailyMap[$day] ?? 0;
        }

        $mean = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / count($values);

        return round(sqrt($variance), 3);
    }

    /** estoque de segurança = z * desvio-padrão * sqrt(lead time); fallback (produto novo, sem stddev) = velocidade * dias de segurança. */
    public function safetyStock(?float $stddev, float $velocityWeighted, int $leadTimeDays, int $safetyDays, float $z): float
    {
        if ($stddev !== null && $leadTimeDays > 0) {
            return round($z * $stddev * sqrt($leadTimeDays), 2);
        }
        return round($velocityWeighted * $safetyDays, 2);
    }

    /** ponto de reposição = velocidade * lead time + estoque de segurança. */
    public function reorderPoint(float $velocityWeighted, int $leadTimeDays, float $safetyStock): float
    {
        return round($velocityWeighted * $leadTimeDays + $safetyStock, 2);
    }

    /** cobertura em dias = estoque disponível / velocidade. Null (nunca 999) quando não há giro. */
    public function coverageDays(int $stock, float $velocityWeighted): ?float
    {
        if ($velocityWeighted <= 0) {
            return null;
        }
        return round(max(0, $stock) / $velocityWeighted, 1);
    }

    /**
     * quantidade sugerida = max(0, velocidade*(lead_time+cobertura_alvo) + estoque_seguranca - estoque - em_transito)
     * "em_transito" é sempre 0 hoje — não há linha de item por pedido de compra no schema (ver docblock da classe).
     * Respeita lote mínimo (moq) e múltiplo de compra do fornecedor.
     */
    public function suggestedQty(
        float $velocityWeighted,
        int $leadTimeDays,
        int $targetCoverageDays,
        float $safetyStock,
        int $stock,
        int $moq,
        int $purchaseMultiple
    ): int {
        $inTransit = 0; // limitação de dado documentada — sem item de purchase_order por produto.
        $raw = $velocityWeighted * ($leadTimeDays + $targetCoverageDays) + $safetyStock - $stock - $inTransit;
        $raw = max(0.0, $raw);

        if ($raw <= 0.0) {
            return 0;
        }

        $moq = max(1, $moq);
        $multiple = max(1, $purchaseMultiple);

        $qty = max($raw, (float) $moq);
        $qty = ceil($qty / $multiple) * $multiple;

        return (int) $qty;
    }

    /**
     * Classificação em 7 categorias (substitui o único "healthy" de antes):
     * RUPTURA (zerado e vende) > CRÍTICO (acaba antes do lead time) > REPOR
     * (abaixo da cobertura alvo) > SAUDÁVEL > EXCESSO (cobertura muito acima
     * do normal) / ESTOQUE_MORTO (capital parado, sem venda) / DESCONTINUADO
     * (zerado e sem venda — fora do radar do comprador).
     */
    public function classifyStatus(
        int $stock,
        bool $hasSales,
        bool $isNew,
        ?float $coverageDays,
        int $leadTimeDays,
        int $targetCoverageDays,
        int $excessThresholdDays
    ): string {
        if ($stock <= 0) {
            return $hasSales ? self::STATUS_RUPTURA : self::STATUS_DESCONTINUADO;
        }

        if (!$hasSales) {
            // Lançado dentro da janela de "estoque morto" — ainda não teve
            // tempo de vender, não é capital parado.
            return $isNew ? self::STATUS_SAUDAVEL : self::STATUS_ESTOQUE_MORTO;
        }

        if ($coverageDays === null) {
            return self::STATUS_ESTOQUE_MORTO;
        }
        if ($coverageDays < $leadTimeDays) {
            return self::STATUS_CRITICO;
        }
        if ($coverageDays < ($leadTimeDays + $targetCoverageDays)) {
            return self::STATUS_REPOR;
        }
        if ($coverageDays > $excessThresholdDays) {
            return self::STATUS_EXCESSO;
        }
        return self::STATUS_SAUDAVEL;
    }

    /** Curva ABC por faturamento de 30 dias (Pareto: 80% acumulado = A, até 95% = B, resto = C). */
    public function abcClasses(array $revenueByProduct): array
    {
        arsort($revenueByProduct);
        $total = array_sum($revenueByProduct);

        $classes = [];
        if ($total <= 0) {
            foreach ($revenueByProduct as $id => $rev) {
                $classes[$id] = null;
            }
            return $classes;
        }

        $cumulative = 0.0;
        foreach ($revenueByProduct as $id => $rev) {
            if ($rev <= 0) {
                $classes[$id] = null;
                continue;
            }
            $cumulative += $rev;
            $pct = $cumulative / $total;
            $classes[$id] = match (true) {
                $pct <= 0.80 => 'A',
                $pct <= 0.95 => 'B',
                default => 'C',
            };
        }

        return $classes;
    }

    /** faturamento em risco nos próximos 30 dias = velocidade * preço * dias projetados de ruptura. */
    public function revenueAtRisk(?float $coverageDays, float $velocityWeighted, float $salePrice): float
    {
        if ($coverageDays === null) {
            return 0.0;
        }
        $daysAtRisk = max(0.0, 30 - $coverageDays);
        if ($daysAtRisk <= 0.0) {
            return 0.0;
        }
        return round($velocityWeighted * $salePrice * $daysAtRisk, 2);
    }

    /** score de priorização = faturamento em risco (peso maior) + urgência do status * peso da curva ABC. */
    public function priorityScore(string $status, ?string $abcClass, float $revenueAtRisk): float
    {
        $urgency = self::URGENCY_WEIGHT[$status] ?? 0;
        $abcMultiplier = self::ABC_MULTIPLIER[$abcClass] ?? 1;
        return round(($revenueAtRisk * 3) + ($urgency * $abcMultiplier), 2);
    }
}
