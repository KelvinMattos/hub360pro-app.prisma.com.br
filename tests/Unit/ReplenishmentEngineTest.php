<?php

namespace Tests\Unit;

use App\Services\ChannelConfigService;
use App\Services\Inventory\ReplenishmentEngine;
use App\Services\PricingEngine;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;

class ReplenishmentEngineTest extends TestCase
{
    private ReplenishmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new ReplenishmentEngine(new PricingEngine(), new ChannelConfigService());
    }

    // --- activeDays ---

    public function test_active_days_uses_full_window_when_stock_present(): void
    {
        $windowStart = Carbon::parse('2026-07-01');
        $this->assertSame(30, $this->engine->activeDays(30, 10, Carbon::parse('2026-07-10'), $windowStart));
    }

    public function test_active_days_uses_full_window_when_never_sold(): void
    {
        $windowStart = Carbon::parse('2026-07-01');
        $this->assertSame(30, $this->engine->activeDays(30, 0, null, $windowStart));
    }

    public function test_active_days_uses_span_to_last_sale_when_out_of_stock(): void
    {
        // Produto zerado hoje: vendeu bem nos primeiros 10 dias da janela de 30 e parou —
        // aproximação assume que ficou sem estoque logo após a última venda.
        $windowStart = Carbon::parse('2026-07-01');
        $lastSale = Carbon::parse('2026-07-10'); // 9 dias após o início + 1
        $this->assertSame(10, $this->engine->activeDays(30, 0, $lastSale, $windowStart));
    }

    public function test_active_days_clamped_to_window_size(): void
    {
        $windowStart = Carbon::parse('2026-07-01');
        $lastSale = Carbon::parse('2026-08-15'); // além da janela
        $this->assertSame(30, $this->engine->activeDays(30, 0, $lastSale, $windowStart));
    }

    public function test_active_days_minimum_one(): void
    {
        $windowStart = Carbon::parse('2026-07-01');
        $lastSale = Carbon::parse('2026-07-01'); // mesmo dia
        $this->assertSame(1, $this->engine->activeDays(30, 0, $lastSale, $windowStart));
    }

    // --- weightedVelocity ---

    public function test_weighted_velocity_default_weights_favor_recent(): void
    {
        $result = $this->engine->weightedVelocity(2.0, 1.0, 0.5, 0.5, 0.3, 0.2);
        $this->assertEqualsWithDelta(1.4, $result, 0.001);
    }

    public function test_weighted_velocity_zero_when_all_zero(): void
    {
        $this->assertSame(0.0, $this->engine->weightedVelocity(0, 0, 0, 0.5, 0.3, 0.2));
    }

    // --- demandStddev ---

    public function test_demand_stddev_null_with_fewer_than_2_active_days(): void
    {
        $this->assertNull($this->engine->demandStddev([], 1, 10, null));
        $this->assertNull($this->engine->demandStddev([], 0, 10, null));
    }

    public function test_demand_stddev_zero_when_constant_demand(): void
    {
        $today = Carbon::now()->toDateString();
        $yesterday = Carbon::now()->subDay()->toDateString();
        $daily = [$today => 5, $yesterday => 5];
        $this->assertSame(0.0, $this->engine->demandStddev($daily, 2, 10, null));
    }

    public function test_demand_stddev_positive_when_demand_varies(): void
    {
        $d0 = Carbon::now()->toDateString();
        $d1 = Carbon::now()->subDay()->toDateString();
        $daily = [$d0 => 10, $d1 => 0];
        $stddev = $this->engine->demandStddev($daily, 2, 10, null);
        $this->assertEqualsWithDelta(5.0, $stddev, 0.001);
    }

    // --- safetyStock ---

    public function test_safety_stock_uses_stddev_formula_when_available(): void
    {
        // z=1.65, stddev=2, lead time=9 => sqrt(9)=3 => 1.65*2*3 = 9.9
        $result = $this->engine->safetyStock(2.0, 5.0, 9, 7, 1.65);
        $this->assertEqualsWithDelta(9.9, $result, 0.01);
    }

    public function test_safety_stock_falls_back_to_velocity_times_safety_days_when_no_stddev(): void
    {
        // Produto novo, sem histórico suficiente para desvio-padrão.
        $result = $this->engine->safetyStock(null, 2.0, 15, 7, 1.65);
        $this->assertSame(14.0, $result);
    }

    public function test_safety_stock_falls_back_when_lead_time_zero(): void
    {
        $result = $this->engine->safetyStock(3.0, 2.0, 0, 7, 1.65);
        $this->assertSame(14.0, $result);
    }

    // --- reorderPoint ---

    public function test_reorder_point_formula(): void
    {
        $this->assertSame(35.0, $this->engine->reorderPoint(2.0, 15, 5.0));
    }

    // --- coverageDays ---

    public function test_coverage_days_null_when_no_velocity(): void
    {
        // Este é o bug corrigido: nunca 999, sempre null quando não há giro.
        $this->assertNull($this->engine->coverageDays(500, 0.0));
    }

    public function test_coverage_days_computed_when_velocity_positive(): void
    {
        $this->assertSame(50.0, $this->engine->coverageDays(100, 2.0));
    }

    public function test_coverage_days_zero_when_stock_zero_but_selling(): void
    {
        $this->assertSame(0.0, $this->engine->coverageDays(0, 2.0));
    }

    // --- suggestedQty ---

    public function test_suggested_qty_zero_when_stock_already_covers_need(): void
    {
        $this->assertSame(0, $this->engine->suggestedQty(1.0, 15, 30, 5.0, 1000, 1, 1));
    }

    public function test_suggested_qty_basic_formula(): void
    {
        // vel=2, lead=15, cobertura=30, safety=10, estoque=20 => 2*45+10-20 = 80
        $this->assertSame(80, $this->engine->suggestedQty(2.0, 15, 30, 10.0, 20, 1, 1));
    }

    public function test_suggested_qty_respects_minimum_order_quantity(): void
    {
        // Necessidade pequena (raw=5) mas moq=50 => sobe para 50.
        $this->assertSame(50, $this->engine->suggestedQty(0.2, 5, 10, 0.0, 0, 50, 1));
    }

    public function test_suggested_qty_rounds_up_to_purchase_multiple(): void
    {
        // raw = 22, múltiplo de compra = 12 => arredonda para 24.
        $this->assertSame(24, $this->engine->suggestedQty(1.0, 10, 12, 0.0, 0, 1, 12));
    }

    // --- classifyStatus ---

    public function test_status_ruptura_when_out_of_stock_with_sales(): void
    {
        $status = $this->engine->classifyStatus(0, true, false, null, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_RUPTURA, $status);
    }

    public function test_status_descontinuado_when_out_of_stock_without_sales(): void
    {
        $status = $this->engine->classifyStatus(0, false, false, null, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_DESCONTINUADO, $status);
    }

    public function test_status_estoque_morto_when_stock_but_no_sales_and_not_new(): void
    {
        $status = $this->engine->classifyStatus(50, false, false, null, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_ESTOQUE_MORTO, $status);
    }

    public function test_status_saudavel_for_brand_new_product_without_history(): void
    {
        // Produto recém-lançado, sem venda ainda — não pode virar "estoque morto".
        $status = $this->engine->classifyStatus(50, false, true, null, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_SAUDAVEL, $status);
    }

    public function test_status_critico_when_coverage_below_lead_time(): void
    {
        $status = $this->engine->classifyStatus(10, true, false, 5.0, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_CRITICO, $status);
    }

    public function test_status_repor_when_coverage_below_target_window(): void
    {
        $status = $this->engine->classifyStatus(10, true, false, 20.0, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_REPOR, $status);
    }

    public function test_status_saudavel_within_normal_range(): void
    {
        $status = $this->engine->classifyStatus(10, true, false, 60.0, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_SAUDAVEL, $status);
    }

    public function test_status_excesso_when_coverage_far_above_target(): void
    {
        $status = $this->engine->classifyStatus(10, true, false, 200.0, 15, 30, 120);
        $this->assertSame(ReplenishmentEngine::STATUS_EXCESSO, $status);
    }

    // --- abcClasses ---

    public function test_abc_classes_pareto_split(): void
    {
        $revenue = ['p1' => 800, 'p2' => 150, 'p3' => 50];
        $classes = $this->engine->abcClasses($revenue);
        $this->assertSame('A', $classes['p1']);
        $this->assertSame('B', $classes['p2']);
        $this->assertSame('C', $classes['p3']);
    }

    public function test_abc_classes_null_for_zero_revenue(): void
    {
        $revenue = ['p1' => 100, 'p2' => 0];
        $classes = $this->engine->abcClasses($revenue);
        $this->assertNull($classes['p2']);
    }

    public function test_abc_classes_all_null_when_total_zero(): void
    {
        $revenue = ['p1' => 0, 'p2' => 0];
        $classes = $this->engine->abcClasses($revenue);
        $this->assertNull($classes['p1']);
        $this->assertNull($classes['p2']);
    }

    // --- revenueAtRisk ---

    public function test_revenue_at_risk_zero_without_velocity(): void
    {
        $this->assertSame(0.0, $this->engine->revenueAtRisk(null, 0.0, 100.0));
    }

    public function test_revenue_at_risk_zero_when_coverage_above_30_days(): void
    {
        $this->assertSame(0.0, $this->engine->revenueAtRisk(45.0, 2.0, 100.0));
    }

    public function test_revenue_at_risk_positive_when_coverage_below_30_days(): void
    {
        // cobre 10 dias, 20 dias em risco, vel=2, preço=50 => 2*50*20 = 2000
        $result = $this->engine->revenueAtRisk(10.0, 2.0, 50.0);
        $this->assertSame(2000.0, $result);
    }

    // --- priorityScore ---

    public function test_priority_score_higher_for_ruptura_class_a_than_saudavel(): void
    {
        $ruptura = $this->engine->priorityScore(ReplenishmentEngine::STATUS_RUPTURA, 'A', 100.0);
        $saudavel = $this->engine->priorityScore(ReplenishmentEngine::STATUS_SAUDAVEL, 'A', 0.0);
        $this->assertGreaterThan($saudavel, $ruptura);
    }

    public function test_priority_score_scales_with_abc_class(): void
    {
        $classA = $this->engine->priorityScore(ReplenishmentEngine::STATUS_CRITICO, 'A', 0.0);
        $classC = $this->engine->priorityScore(ReplenishmentEngine::STATUS_CRITICO, 'C', 0.0);
        $this->assertGreaterThan($classC, $classA);
    }
}
