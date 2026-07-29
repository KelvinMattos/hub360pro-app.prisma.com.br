<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifica cada SKU em 4 eixos, a partir de dados reais (produtos + pedidos
 * importados) — nenhum número aqui é estimado ou inventado, mas é um
 * heurística declarada, não um modelo de machine learning:
 *
 *  - Papel de precificação: quadrante margem x volume contra a MEDIANA da
 *    própria empresa (estrela / alavanca / nicho / reavaliar).
 *  - Ciclo de vida: tendência de venda comparando os últimos 30 dias contra
 *    os 60 e os 90 dias anteriores (crescimento / estável / declínio / novo / sem_giro).
 *  - Saúde de estoque: cobertura (dias), giro e aging desde o lançamento,
 *    combinados num índice 0-100.
 *  - Posição competitiva: reaproveita EXATAMENTE as 4 categorias de
 *    MarketMonitorService (desconhecido / alerta / perdendo / vendendo) para
 *    não divergir do resto do app.
 *
 * Roda 1x/dia via `sku:classify-strategy` — a tela de Segmentação só LÊ a
 * tabela `sku_strategy`, nunca calcula na hora do request.
 */
class SkuStrategyClassifier
{
    public function classifyCompany(int $companyId, ?Carbon $now = null): int
    {
        $now = $now ? $now->copy() : Carbon::now();

        if (!Schema::hasTable('products') || !Schema::hasTable('sku_strategy')) {
            return 0;
        }

        $cols = Schema::getColumnListing('products');
        $has = fn (string $c) => in_array($c, $cols, true);

        $select = ['id'];
        foreach (['sku', 'sale_price', 'cost_price', 'price', 'promotional_price',
                  'stock_quantity', 'launched_at', 'market_price', 'buybox_winner'] as $c) {
            if ($has($c)) $select[] = $c;
        }

        $products = DB::table('products')->where('company_id', $companyId)->select($select)->get();
        if ($products->isEmpty()) {
            return 0;
        }

        $productIds = $products->pluck('id')->all();
        $volumes = $this->salesVolumeByWindow($companyId, $productIds, $now);

        $margins = [];
        foreach ($products as $p) {
            $margins[$p->id] = $this->grossMarginPct($p->sale_price ?? null, $p->cost_price ?? null);
        }
        $marginMedian = $this->median(array_values($margins));
        $volumeMedian = $this->median(array_map(fn ($id) => (float) $volumes[$id]['v30'], $productIds));

        $rows = [];
        foreach ($products as $p) {
            $vol = $volumes[$p->id];
            $marginPct = $margins[$p->id];

            $role = $this->pricingRole($marginPct, $vol['v30'], $marginMedian, $volumeMedian);
            [$lifecycle, $trend3090, $trend90180] = $this->lifecycleStage(
                $vol['v30'], $vol['v90'], $vol['v180'], $p->launched_at ?? null, $now
            );
            $health = $this->stockHealth(
                (int) ($p->stock_quantity ?? 0), $vol['v30'], $p->launched_at ?? null, $vol['last_sale_at'], $now
            );
            [$position, $gap] = $this->competitivePosition($p);
            $buyboxDistance = $this->buyboxDistance($p, $gap);

            $rows[] = [
                'company_id' => $companyId,
                'product_id' => $p->id,
                'sku' => $p->sku ?? null,
                'pricing_role' => $role,
                'margin_pct' => $marginPct,
                'volume_30d' => $vol['v30'],
                'lifecycle_stage' => $lifecycle,
                'trend_30_90_pct' => $trend3090,
                'trend_90_180_pct' => $trend90180,
                'stock_health_index' => $health['index'],
                'stock_coverage_days' => $health['coverage_days'],
                'stock_turnover' => $health['turnover'],
                'stock_aging_days' => $health['aging_days'],
                'competitive_position' => $position,
                'market_gap_pct' => $gap,
                'buybox_distance_pct' => $buyboxDistance,
                'computed_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('sku_strategy')->upsert(
                $chunk,
                ['company_id', 'product_id'],
                ['sku', 'pricing_role', 'margin_pct', 'volume_30d', 'lifecycle_stage',
                 'trend_30_90_pct', 'trend_90_180_pct', 'stock_health_index', 'stock_coverage_days',
                 'stock_turnover', 'stock_aging_days', 'competitive_position', 'market_gap_pct',
                 'buybox_distance_pct', 'computed_at', 'updated_at']
            );
        }

        return count($rows);
    }

    /** Volume vendido por produto nas janelas de 30/90/180 dias, usando a data REAL do pedido (CLAUDE.md §5.1). */
    private function salesVolumeByWindow(int $companyId, array $productIds, Carbon $now): array
    {
        $result = [];
        foreach ($productIds as $id) {
            $result[$id] = ['v30' => 0, 'v90' => 0, 'v180' => 0, 'last_sale_at' => null];
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

        $d30 = $now->copy()->subDays(30);
        $d90 = $now->copy()->subDays(90);
        $d180 = $now->copy()->subDays(180);

        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.company_id', $companyId)
            ->whereIn('order_items.product_id', $productIds)
            ->where("orders.$dateCol", '>=', $d180)
            ->select('order_items.product_id', "orders.$dateCol as odate", 'order_items.quantity')
            ->get();

        foreach ($rows as $r) {
            if (!isset($result[$r->product_id])) continue;
            $odate = Carbon::parse($r->odate);
            $qty = (int) $r->quantity;
            $result[$r->product_id]['v180'] += $qty;
            if ($odate->gte($d90)) $result[$r->product_id]['v90'] += $qty;
            if ($odate->gte($d30)) $result[$r->product_id]['v30'] += $qty;
            $lastSaleAt = $result[$r->product_id]['last_sale_at'];
            if ($lastSaleAt === null || $odate->gt($lastSaleAt)) {
                $result[$r->product_id]['last_sale_at'] = $odate;
            }
        }

        return $result;
    }

    /** Margem bruta % = (venda - custo) / venda. Sem canal conhecido por produto, não dá pra usar PricingEngine aqui. */
    public function grossMarginPct($salePrice, $costPrice): ?float
    {
        $sale = (float) ($salePrice ?? 0);
        if ($sale <= 0) return null;
        $cost = (float) ($costPrice ?? 0);
        return round((($sale - $cost) / $sale) * 100, 2);
    }

    public function median(array $values): ?float
    {
        $values = array_values(array_filter($values, fn ($v) => $v !== null));
        sort($values);
        $count = count($values);
        if ($count === 0) return null;
        $mid = intdiv($count, 2);
        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2;
        }
        return (float) $values[$mid];
    }

    /** Quadrante margem x volume contra a mediana da empresa. */
    public function pricingRole(?float $marginPct, int $volume30, ?float $marginMedian, ?float $volumeMedian): string
    {
        if ($marginPct === null || $marginMedian === null || $volumeMedian === null) {
            return 'sem_dado';
        }

        $highMargin = $marginPct >= $marginMedian;
        $highVolume = $volume30 >= $volumeMedian;

        return match (true) {
            $highMargin && $highVolume => 'estrela',
            !$highMargin && $highVolume => 'alavanca',
            $highMargin && !$highVolume => 'nicho',
            default => 'reavaliar',
        };
    }

    /** Variação % entre a taxa mensal recente e a anterior. Null quando não há base de comparação. */
    public function trendPct(float $recentMonthly, float $priorMonthly): ?float
    {
        if ($priorMonthly <= 0) {
            return $recentMonthly > 0 ? 100.0 : null;
        }
        return round((($recentMonthly - $priorMonthly) / $priorMonthly) * 100, 2);
    }

    /** @return array{0:string,1:?float,2:?float} [estágio, tendência 30v90d%, tendência 90v180d%] */
    public function lifecycleStage(int $v30, int $v90, int $v180, $launchedAt, Carbon $now): array
    {
        if ($launchedAt) {
            $agingDays = abs($now->diffInDays(Carbon::parse($launchedAt)));
            if ($agingDays <= 30) {
                return ['novo', null, null];
            }
        }

        if ($v30 === 0 && $v90 === 0 && $v180 === 0) {
            return ['sem_giro', null, null];
        }

        // Taxas mensais aproximadas por período, para comparar "ritmos" e não totais acumulados.
        $monthlyRecent = (float) $v30;
        $monthlyMid = max(0, $v90 - $v30) / 2;   // dias 31-90 (2 meses)
        $monthlyOld = max(0, $v180 - $v90) / 3;  // dias 91-180 (3 meses)

        $trend3090 = $this->trendPct($monthlyRecent, $monthlyMid);
        $trend90180 = $this->trendPct($monthlyMid, $monthlyOld);

        if ($trend3090 === null) {
            $stage = $v30 > 0 ? 'novo' : 'sem_giro';
        } elseif ($trend3090 >= 15) {
            $stage = 'crescimento';
        } elseif ($trend3090 <= -15) {
            $stage = 'declinio';
        } else {
            $stage = 'estavel';
        }

        return [$stage, $trend3090, $trend90180];
    }

    /**
     * Índice 0-100 = 50% cobertura + 30% giro + 20% aging. Heurística declarada
     * (não é IA): pesos e faixas documentados aqui, ajustáveis depois.
     *
     * Aging usa a data da ÚLTIMA VENDA (não o lançamento) — um best-seller
     * evergreen de 2 anos que vende toda semana não pode ser penalizado só
     * por ser "velho"; o que importa é há quanto tempo ficou parado. Sem
     * nenhuma venda ainda, cai no fallback do lançamento.
     *
     * @return array{index:int,coverage_days:?float,turnover:?float,aging_days:?int}
     */
    public function stockHealth(int $stock, int $v30, $launchedAt, $lastSaleAt, Carbon $now): array
    {
        $dailyVelocity = $v30 / 30;

        if ($stock <= 0) {
            $coverageDays = 0.0;
            $coverageScore = 0.0;
            $turnover = null;
            $turnoverScore = 0.0;
        } elseif ($dailyVelocity <= 0) {
            $coverageDays = null; // sem giro, cobertura "infinita"
            $coverageScore = 20.0;
            $turnover = 0.0;
            $turnoverScore = 0.0;
        } else {
            $coverageDays = round($stock / $dailyVelocity, 1);
            if ($coverageDays < 3) {
                $coverageScore = max(0.0, ($coverageDays / 3) * 40);
            } elseif ($coverageDays <= 60) {
                $coverageScore = 100.0;
            } else {
                $coverageScore = max(0.0, 100 - ($coverageDays - 60));
            }
            $turnover = round($v30 / $stock, 3);
            $turnoverScore = min(100.0, $turnover * 100);
        }

        $agingAnchor = $lastSaleAt ?: $launchedAt;
        $agingDays = $agingAnchor ? abs($now->diffInDays(Carbon::parse($agingAnchor))) : null;
        if ($agingDays === null) {
            $agingScore = 50.0;
        } elseif ($agingDays <= 90) {
            $agingScore = 100.0;
        } else {
            $agingScore = max(0.0, 100 - (($agingDays - 90) / 3));
        }

        $index = (int) round(0.5 * $coverageScore + 0.3 * $turnoverScore + 0.2 * $agingScore);

        return [
            'index' => max(0, min(100, $index)),
            'coverage_days' => $coverageDays,
            'turnover' => $turnover,
            'aging_days' => $agingDays,
        ];
    }

    /** Mesmas 4 categorias de MarketMonitorService::statusSql(), calculadas em PHP sobre a linha já carregada. */
    public function competitivePosition(object $p): array
    {
        $effective = null;
        foreach (['promotional_price', 'sale_price', 'price'] as $c) {
            if (!empty($p->$c ?? null)) {
                $effective = (float) $p->$c;
                break;
            }
        }

        $market = (isset($p->market_price) && (float) $p->market_price > 0) ? (float) $p->market_price : null;

        if ($market === null) {
            return ['desconhecido', null];
        }

        $gap = $effective !== null ? round((($effective - $market) / $market) * 100, 2) : null;

        if ((int) ($p->stock_quantity ?? 0) <= 0) {
            return ['alerta', $gap];
        }
        if ($effective !== null && $effective > $market) {
            return ['perdendo', $gap];
        }

        return ['vendendo', $gap];
    }

    /** Distância (%) até ganhar a Buy Box. 0 quando já ganhamos, null quando não há dado de vendedor vencedor. */
    public function buyboxDistance(object $p, ?float $gap): ?float
    {
        $winner = $p->buybox_winner ?? null;
        if ($winner === null || $winner === '') {
            return null;
        }
        if ((bool) $winner) {
            return 0.0;
        }
        return $gap !== null ? max(0.0, $gap) : null;
    }
}
