<?php

namespace Tests\Feature;

use App\Models\DecisionCycle;
use App\Services\DecisionCycleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Testa o ciclo de vida completo do DecisionCycle contra o schema real:
 * simular -> iniciar -> aplicar lote -> freio -> medir ROI.
 */
class DecisionCycleEngineTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private DecisionCycleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->engine = app(DecisionCycleEngine::class);
    }

    private function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'sku' => 'SKU-' . uniqid(),
            'title' => 'Produto Teste',
            'brand' => 'MarcaX',
            'price' => 200.00,
            'cost_price' => 100.00,
            'stock_quantity' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertSale(int $productId, Carbon $date, float $unitPrice, float $unitCost, int $qty = 1): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => $unitPrice * $qty,
            'date_created' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku' => 'x',
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'unit_cost' => $unitCost,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCycle(array $overrides = []): DecisionCycle
    {
        return DecisionCycle::create(array_merge([
            'company_id' => $this->companyId,
            'objective' => 'Testar aumento de preço de 5%',
            'scope' => ['brand' => 'MarcaX'],
            'limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'max_change_pct' => 30, 'control_pct' => 20, 'batch_size' => 10, 'max_volume_drop_pct' => 30],
            'duration_days' => 14,
            'status' => DecisionCycle::STATUS_DRAFT,
        ], $overrides));
    }

    public function test_resolve_scope_by_brand(): void
    {
        $p1 = $this->makeProduct(['brand' => 'MarcaX']);
        $p2 = $this->makeProduct(['brand' => 'MarcaY']);

        $ids = $this->engine->resolveScopeProductIds($this->companyId, ['brand' => 'MarcaX']);

        $this->assertSame([$p1], $ids);
    }

    public function test_resolve_scope_by_pricing_role(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        DB::table('sku_strategy')->insert([
            ['company_id' => $this->companyId, 'product_id' => $p1, 'pricing_role' => 'estrela', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->companyId, 'product_id' => $p2, 'pricing_role' => 'reavaliar', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $ids = $this->engine->resolveScopeProductIds($this->companyId, ['pricing_role' => 'estrela']);

        $this->assertSame([$p1], $ids);
    }

    public function test_simulate_does_not_write_any_price(): void
    {
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100]);
        $this->insertSale($productId, now()->subDays(5), 200, 100, 10);

        $cycle = $this->makeCycle();
        $result = $this->engine->simulate($cycle);

        $priceAfter = DB::table('products')->where('id', $productId)->value('price');
        $this->assertSame(200.0, (float) $priceAfter);
        $this->assertSame(DecisionCycle::STATUS_SIMULATED, $cycle->fresh()->status);
        $this->assertArrayHasKey('assumption', $result);
        $this->assertSame(1, $result['scope_count']);
    }

    public function test_start_splits_treatment_and_control_and_never_overlaps(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeProduct(['brand' => 'MarcaX']);
        }
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'control_pct' => 20, 'batch_size' => 5]]);

        $this->engine->start($cycle);
        $cycle->refresh();

        $this->assertSame(DecisionCycle::STATUS_RUNNING, $cycle->status);
        $this->assertNotEmpty($cycle->treatment_product_ids);
        $this->assertNotEmpty($cycle->control_product_ids);
        $this->assertEmpty(array_intersect($cycle->treatment_product_ids, $cycle->control_product_ids));
        $this->assertSame(10, count($cycle->treatment_product_ids) + count($cycle->control_product_ids));
        $this->assertNotNull($cycle->started_at);
        $this->assertNotNull($cycle->baseline_snapshot);
    }

    public function test_start_throws_when_scope_is_empty(): void
    {
        $cycle = $this->makeCycle(['scope' => ['brand' => 'MarcaInexistente']]);

        $this->expectException(\RuntimeException::class);
        $this->engine->start($cycle);
    }

    public function test_apply_next_batch_changes_price_and_logs(): void
    {
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100, 'brand' => 'MarcaX']);
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();

        $result = $this->engine->applyNextBatch($cycle);

        $this->assertSame(1, $result['applied']);
        $this->assertSame(0, $result['blocked']);
        // A gravação vai para promotional_price (mesma prioridade de coluna do RepricingEngine) —
        // 'price' é só o preço de tabela original, nunca sobrescrito.
        $newPrice = DB::table('products')->where('id', $productId)->value('promotional_price');
        $this->assertEqualsWithDelta(210.0, (float) $newPrice, 0.5);
        $this->assertDatabaseHas('decision_cycle_logs', ['decision_cycle_id' => $cycle->id, 'product_id' => $productId, 'action' => 'applied']);
    }

    public function test_apply_next_batch_blocks_price_below_floor(): void
    {
        // custo=100, margem mínima=10%, encargos do canal (Netshoes default ~32%) -> piso bem acima
        // de uma redução de 90% no preço.
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100, 'brand' => 'MarcaX']);
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => -90, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();

        $result = $this->engine->applyNextBatch($cycle);

        $this->assertSame(0, $result['applied']);
        $this->assertSame(1, $result['blocked']);
        $priceUnchanged = DB::table('products')->where('id', $productId)->value('price');
        $this->assertSame(200.0, (float) $priceUnchanged);
        $this->assertDatabaseHas('decision_cycle_logs', ['decision_cycle_id' => $cycle->id, 'product_id' => $productId, 'action' => 'blocked']);
    }

    public function test_apply_next_batch_never_touches_control_group(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeProduct(['brand' => 'MarcaX', 'price' => 200, 'cost_price' => 100]);
        }
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'control_pct' => 50, 'batch_size' => 20]]);
        $this->engine->start($cycle);
        $cycle->refresh();

        $this->engine->applyNextBatch($cycle);

        foreach ($cycle->control_product_ids as $controlId) {
            $price = DB::table('products')->where('id', $controlId)->value('price');
            $this->assertEqualsWithDelta(200.0, (float) $price, 0.01, "Produto de controle #{$controlId} não deveria ter o preço alterado.");
        }
    }

    public function test_circuit_breaker_trips_on_floor_violation(): void
    {
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100, 'brand' => 'MarcaX']);
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();
        $this->engine->applyNextBatch($cycle);
        $cycle->refresh();

        // Custo dispara depois de aplicado -> preço atual cai abaixo do novo piso.
        DB::table('products')->where('id', $productId)->update(['cost_price' => 500]);

        $tripped = $this->engine->checkCircuitBreaker($cycle);

        $this->assertTrue($tripped);
        $this->assertSame(DecisionCycle::STATUS_ABORTED, $cycle->fresh()->status);
        $this->assertNotNull($cycle->fresh()->abort_reason);
    }

    public function test_circuit_breaker_does_not_trip_when_healthy(): void
    {
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100, 'brand' => 'MarcaX']);
        $this->insertSale($productId, now()->subDays(5), 200, 100, 10);
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();
        $this->engine->applyNextBatch($cycle);
        $cycle->refresh();

        // Passa do período de carência (3 dias) do freio de volume, mantendo o ritmo de vendas.
        $cycle->started_at = now()->subDays(5);
        $cycle->save();
        $this->insertSale($productId, now()->subDay(), 210, 100, 10);

        $tripped = $this->engine->checkCircuitBreaker($cycle);

        $this->assertFalse($tripped);
        $this->assertSame(DecisionCycle::STATUS_RUNNING, $cycle->fresh()->status);
    }

    public function test_circuit_breaker_trips_on_volume_drop(): void
    {
        $productId = $this->makeProduct(['price' => 200, 'cost_price' => 100, 'brand' => 'MarcaX']);
        // Baseline: vendas saudáveis nos 30 dias antes do início do ciclo.
        for ($i = 0; $i < 10; $i++) {
            $this->insertSale($productId, now()->subDays(20 + $i), 200, 100, 5);
        }
        $cycle = $this->makeCycle(['limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10, 'max_volume_drop_pct' => 20]]);
        $this->engine->start($cycle);
        $cycle->refresh();

        // Passa do período de carência do freio (3 dias) sem nenhuma venda depois do início -> volume despenca para 0.
        $cycle->started_at = now()->subDays(5);
        $cycle->save();

        $tripped = $this->engine->checkCircuitBreaker($cycle);

        $this->assertTrue($tripped);
        $this->assertStringContainsString('volume', $cycle->fresh()->abort_reason);
    }

    public function test_measure_roi_returns_null_without_started_at(): void
    {
        $cycle = $this->makeCycle();
        $this->assertNull($this->engine->measureRoi($cycle));
    }

    public function test_measure_roi_computes_real_did_from_orders(): void
    {
        $treatmentProduct = $this->makeProduct(['brand' => 'MarcaX', 'price' => 200, 'cost_price' => 100]);
        $controlProduct = $this->makeProduct(['brand' => 'MarcaX', 'price' => 200, 'cost_price' => 100]);

        // Baseline (30 dias antes do início): ambos vendem igual.
        for ($i = 1; $i <= 10; $i++) {
            $this->insertSale($treatmentProduct, now()->subDays(20 + $i), 200, 100, 1);
            $this->insertSale($controlProduct, now()->subDays(20 + $i), 200, 100, 1);
        }

        $cycle = $this->makeCycle(['scope' => ['product_ids' => [$treatmentProduct, $controlProduct]], 'limits' => ['price_change_pct' => 5, 'control_pct' => 50, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();

        // Força o produto de controle a ser o $controlProduct explicitamente para o teste ser determinístico.
        if (!in_array($controlProduct, $cycle->control_product_ids)) {
            [$cycle->treatment_product_ids, $cycle->control_product_ids] = [$cycle->control_product_ids, $cycle->treatment_product_ids];
            $cycle->save();
        }

        // started_at fica um pouco no passado para garantir que as vendas "depois" (agora)
        // caiam dentro da janela whereBetween(started_at, now()).
        $cycle->started_at = now()->subDays(2);
        $cycle->save();

        // "Depois" do início: tratamento vende mais, controle mantém o ritmo.
        $this->insertSale($treatmentProduct, now(), 210, 100, 20);
        $this->insertSale($controlProduct, now(), 200, 100, 10);

        $roi = $this->engine->measureRoi($cycle);

        $this->assertNotNull($roi);
        $this->assertArrayHasKey('roi_revenue_rs', $roi);
        $this->assertGreaterThan(0, $roi['roi_revenue_rs']);
        // assertEquals (não assertSame): JSON não distingue int/float para números inteiros no round-trip.
        $this->assertEquals($roi, $cycle->fresh()->roi_result);
    }

    public function test_tick_applies_batches_then_completes_and_measures_roi(): void
    {
        $productId = $this->makeProduct(['brand' => 'MarcaX', 'price' => 200, 'cost_price' => 100]);
        $this->insertSale($productId, now()->subDays(5), 200, 100, 5);

        $cycle = $this->makeCycle(['duration_days' => 1, 'limits' => ['price_change_pct' => 5, 'control_pct' => 0, 'batch_size' => 10]]);
        $this->engine->start($cycle);
        $cycle->refresh();
        $cycle->started_at = now()->subDays(2); // já vencido
        $cycle->save();

        $r1 = $this->engine->tick($cycle);
        $this->assertSame('batch_applied', $r1['action']);

        $cycle->refresh();
        $r2 = $this->engine->tick($cycle);
        $this->assertSame('completed', $r2['action']);
        $this->assertSame(DecisionCycle::STATUS_COMPLETED, $cycle->fresh()->status);
        $this->assertNotNull($cycle->fresh()->roi_result);
    }

    public function test_tick_skips_when_not_running(): void
    {
        $cycle = $this->makeCycle(['status' => DecisionCycle::STATUS_DRAFT]);
        $result = $this->engine->tick($cycle);
        $this->assertSame('skipped', $result['action']);
    }
}
