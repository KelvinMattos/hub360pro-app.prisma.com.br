<?php

namespace Tests\Unit;

use App\Services\SkuStrategyClassifier;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class SkuStrategyClassifierTest extends TestCase
{
    private SkuStrategyClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new SkuStrategyClassifier();
    }

    // --- grossMarginPct ---

    public function test_gross_margin_pct_basic(): void
    {
        $this->assertSame(50.0, $this->classifier->grossMarginPct(100, 50));
    }

    public function test_gross_margin_pct_null_when_no_sale_price(): void
    {
        $this->assertNull($this->classifier->grossMarginPct(0, 50));
        $this->assertNull($this->classifier->grossMarginPct(null, 50));
    }

    public function test_gross_margin_pct_treats_missing_cost_as_zero(): void
    {
        $this->assertSame(100.0, $this->classifier->grossMarginPct(80, null));
    }

    public function test_gross_margin_pct_can_be_negative(): void
    {
        $this->assertSame(-25.0, $this->classifier->grossMarginPct(80, 100));
    }

    // --- median ---

    public function test_median_odd_count(): void
    {
        $this->assertSame(5.0, $this->classifier->median([1, 5, 9]));
    }

    public function test_median_even_count_averages_middle_two(): void
    {
        $this->assertSame(5.0, $this->classifier->median([1, 4, 6, 9]));
    }

    public function test_median_ignores_nulls(): void
    {
        $this->assertSame(5.0, $this->classifier->median([null, 1, 9, null]));
    }

    public function test_median_of_empty_array_is_null(): void
    {
        $this->assertNull($this->classifier->median([]));
    }

    // --- pricingRole ---

    public function test_pricing_role_estrela_high_margin_high_volume(): void
    {
        $this->assertSame('estrela', $this->classifier->pricingRole(40.0, 100, 20.0, 50.0));
    }

    public function test_pricing_role_alavanca_low_margin_high_volume(): void
    {
        $this->assertSame('alavanca', $this->classifier->pricingRole(10.0, 100, 20.0, 50.0));
    }

    public function test_pricing_role_nicho_high_margin_low_volume(): void
    {
        $this->assertSame('nicho', $this->classifier->pricingRole(40.0, 10, 20.0, 50.0));
    }

    public function test_pricing_role_reavaliar_low_margin_low_volume(): void
    {
        $this->assertSame('reavaliar', $this->classifier->pricingRole(10.0, 10, 20.0, 50.0));
    }

    public function test_pricing_role_sem_dado_without_margin(): void
    {
        $this->assertSame('sem_dado', $this->classifier->pricingRole(null, 100, 20.0, 50.0));
    }

    public function test_pricing_role_sem_dado_without_company_medians(): void
    {
        $this->assertSame('sem_dado', $this->classifier->pricingRole(40.0, 100, null, null));
    }

    // --- trendPct ---

    public function test_trend_pct_growth(): void
    {
        $this->assertSame(100.0, $this->classifier->trendPct(20, 10));
    }

    public function test_trend_pct_decline(): void
    {
        $this->assertSame(-50.0, $this->classifier->trendPct(5, 10));
    }

    public function test_trend_pct_null_without_prior_base_and_no_recent(): void
    {
        $this->assertNull($this->classifier->trendPct(0, 0));
    }

    public function test_trend_pct_100_when_growing_from_zero(): void
    {
        $this->assertSame(100.0, $this->classifier->trendPct(10, 0));
    }

    // --- lifecycleStage ---

    public function test_lifecycle_novo_within_30_days_of_launch(): void
    {
        $now = Carbon::parse('2026-07-29');
        [$stage, $t3090, $t90180] = $this->classifier->lifecycleStage(5, 5, 5, $now->copy()->subDays(10), $now);
        $this->assertSame('novo', $stage);
        $this->assertNull($t3090);
        $this->assertNull($t90180);
    }

    public function test_lifecycle_sem_giro_when_never_sold(): void
    {
        $now = Carbon::parse('2026-07-29');
        [$stage] = $this->classifier->lifecycleStage(0, 0, 0, $now->copy()->subYear(), $now);
        $this->assertSame('sem_giro', $stage);
    }

    public function test_lifecycle_crescimento_when_recent_rate_up_sharply(): void
    {
        $now = Carbon::parse('2026-07-29');
        // v30=30 (30/mês), meses anteriores rate=10/mês (v90-v30=20 em 2 meses) => +200%
        [$stage, $t3090] = $this->classifier->lifecycleStage(30, 50, 80, $now->copy()->subYear(), $now);
        $this->assertSame('crescimento', $stage);
        $this->assertGreaterThan(15, $t3090);
    }

    public function test_lifecycle_declinio_when_recent_rate_down_sharply(): void
    {
        $now = Carbon::parse('2026-07-29');
        // v30=2 (2/mês), meses anteriores rate=20/mês (v90-v30=40 em 2 meses) => -90%
        [$stage, $t3090] = $this->classifier->lifecycleStage(2, 42, 100, $now->copy()->subYear(), $now);
        $this->assertSame('declinio', $stage);
        $this->assertLessThan(-15, $t3090);
    }

    public function test_lifecycle_estavel_when_rate_similar(): void
    {
        $now = Carbon::parse('2026-07-29');
        // v30=20, meses anteriores rate=20/mês (v90-v30=40 em 2 meses) => 0%
        [$stage, $t3090] = $this->classifier->lifecycleStage(20, 60, 120, $now->copy()->subYear(), $now);
        $this->assertSame('estavel', $stage);
        $this->assertSame(0.0, $t3090);
    }

    public function test_lifecycle_crescimento_when_only_recent_sales_and_old_launch_date(): void
    {
        // Produto antigo mas só começou a vender agora (ex.: voltou de ruptura de estoque) —
        // sem vendas nos meses anteriores é uma alta de uma base zero, contada como crescimento.
        $now = Carbon::parse('2026-07-29');
        [$stage, $t3090] = $this->classifier->lifecycleStage(10, 10, 10, $now->copy()->subYears(2), $now);
        $this->assertSame('crescimento', $stage);
        $this->assertSame(100.0, $t3090);
    }

    // --- stockHealth ---

    public function test_stock_health_zero_stock_scores_low(): void
    {
        $now = Carbon::parse('2026-07-29');
        $result = $this->classifier->stockHealth(0, 30, $now->copy()->subYear(), $now->copy()->subDays(2), $now);
        $this->assertSame(0.0, $result['coverage_days']);
        $this->assertNull($result['turnover']);
        $this->assertLessThanOrEqual(20, $result['index']);
    }

    public function test_stock_health_healthy_coverage_scores_high(): void
    {
        $now = Carbon::parse('2026-07-29');
        // 100 unidades, vende 60/mês => cobertura ~50 dias (banda saudável), giro 0.6, vendeu ontem.
        $result = $this->classifier->stockHealth(100, 60, $now->copy()->subYear(), $now->copy()->subDay(), $now);
        $this->assertEqualsWithDelta(50.0, $result['coverage_days'], 0.5);
        $this->assertGreaterThanOrEqual(80, $result['index']);
    }

    public function test_stock_health_evergreen_bestseller_not_penalized_for_being_old(): void
    {
        // Lançado há 2 anos, mas vende bem e vendeu ontem — aging não pode penalizar isso.
        $now = Carbon::parse('2026-07-29');
        $result = $this->classifier->stockHealth(100, 60, $now->copy()->subYears(2), $now->copy()->subDay(), $now);
        $this->assertGreaterThanOrEqual(80, $result['index']);
    }

    public function test_stock_health_dead_stock_no_sales_scores_low(): void
    {
        $now = Carbon::parse('2026-07-29');
        $result = $this->classifier->stockHealth(50, 0, $now->copy()->subYears(2), null, $now);
        $this->assertNull($result['coverage_days']);
        $this->assertSame(0.0, $result['turnover']);
        $this->assertLessThan(30, $result['index']);
    }

    public function test_stock_health_excess_coverage_penalized(): void
    {
        $now = Carbon::parse('2026-07-29');
        // 1000 unidades vendendo 10/mês => cobertura ~3000 dias, muito parado.
        $result = $this->classifier->stockHealth(1000, 10, $now->copy()->subYear(), $now->copy()->subDays(3), $now);
        $this->assertGreaterThan(500, $result['coverage_days']);
        $this->assertLessThan(30, $result['index']);
    }

    public function test_stock_health_index_always_clamped_0_100(): void
    {
        $now = Carbon::parse('2026-07-29');
        $result = $this->classifier->stockHealth(1, 1000, $now, $now, $now);
        $this->assertGreaterThanOrEqual(0, $result['index']);
        $this->assertLessThanOrEqual(100, $result['index']);
    }

    // --- competitivePosition ---

    public function test_competitive_position_desconhecido_without_market_price(): void
    {
        $p = (object) ['sale_price' => 100, 'stock_quantity' => 10, 'market_price' => null];
        [$status, $gap] = $this->classifier->competitivePosition($p);
        $this->assertSame('desconhecido', $status);
        $this->assertNull($gap);
    }

    public function test_competitive_position_alerta_when_out_of_stock(): void
    {
        $p = (object) ['sale_price' => 100, 'stock_quantity' => 0, 'market_price' => 90];
        [$status] = $this->classifier->competitivePosition($p);
        $this->assertSame('alerta', $status);
    }

    public function test_competitive_position_perdendo_when_price_above_market(): void
    {
        $p = (object) ['sale_price' => 120, 'stock_quantity' => 10, 'market_price' => 100];
        [$status, $gap] = $this->classifier->competitivePosition($p);
        $this->assertSame('perdendo', $status);
        $this->assertSame(20.0, $gap);
    }

    public function test_competitive_position_vendendo_when_price_at_or_below_market(): void
    {
        $p = (object) ['sale_price' => 90, 'stock_quantity' => 10, 'market_price' => 100];
        [$status, $gap] = $this->classifier->competitivePosition($p);
        $this->assertSame('vendendo', $status);
        $this->assertSame(-10.0, $gap);
    }

    public function test_competitive_position_prefers_promotional_price(): void
    {
        $p = (object) ['promotional_price' => 80, 'sale_price' => 120, 'stock_quantity' => 10, 'market_price' => 100];
        [$status] = $this->classifier->competitivePosition($p);
        $this->assertSame('vendendo', $status);
    }

    // --- buyboxDistance ---

    public function test_buybox_distance_zero_when_winning(): void
    {
        $p = (object) ['buybox_winner' => true];
        $this->assertSame(0.0, $this->classifier->buyboxDistance($p, 15.0));
    }

    public function test_buybox_distance_equals_gap_when_losing(): void
    {
        $p = (object) ['buybox_winner' => false];
        $this->assertSame(15.0, $this->classifier->buyboxDistance($p, 15.0));
    }

    public function test_buybox_distance_null_when_unknown(): void
    {
        $p = (object) ['buybox_winner' => null];
        $this->assertNull($this->classifier->buyboxDistance($p, 15.0));
    }

    public function test_buybox_distance_never_negative(): void
    {
        $p = (object) ['buybox_winner' => false];
        $this->assertSame(0.0, $this->classifier->buyboxDistance($p, -5.0));
    }
}
