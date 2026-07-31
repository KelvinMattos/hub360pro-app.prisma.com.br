<?php

namespace Tests\Feature\Marketing;

use App\Services\Marketing\MarketingOpportunityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MarketingOpportunityService NUNCA recalcula velocidade/estoque — só lê
 * `replenishment_plan` (motor de Reposição Inteligente) e `products.launched_at`.
 */
class MarketingOpportunityServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private MarketingOpportunityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = app(MarketingOpportunityService::class);
    }

    private function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'sku' => 'SKU-' . uniqid(),
            'title' => 'Produto Teste',
            'sale_price' => 100,
            'cost_price' => 50,
            'stock_quantity' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function makeReplenishmentRow(int $productId, array $overrides = []): void
    {
        DB::table('replenishment_plan')->insert(array_merge([
            'company_id' => $this->companyId,
            'product_id' => $productId,
            'sku' => 'SKU', 'title' => 'Produto', 'brand' => null,
            'stock' => 10, 'cost_price' => 50, 'sale_price' => 100,
            'velocity_weighted' => 0, 'status' => 'saudavel',
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_launches_returns_products_launched_within_window(): void
    {
        $recent = $this->makeProduct(['launched_at' => now()->subDays(10)]);
        $old = $this->makeProduct(['launched_at' => now()->subDays(400)]);
        $never = $this->makeProduct(['launched_at' => null]);

        $result = $this->service->launches($this->companyId);

        $ids = collect($result)->pluck('product_id');
        $this->assertTrue($ids->contains($recent));
        $this->assertFalse($ids->contains($old));
        $this->assertFalse($ids->contains($never));
    }

    public function test_launches_includes_real_velocity_when_available(): void
    {
        $productId = $this->makeProduct(['launched_at' => now()->subDays(5)]);
        $this->makeReplenishmentRow($productId, ['velocity_weighted' => 2.5]);

        $result = $this->service->launches($this->companyId);

        $row = collect($result)->firstWhere('product_id', $productId);
        $this->assertSame(2.5, $row['velocity']);
    }

    public function test_best_sellers_only_returns_abc_class_a_ordered_by_revenue(): void
    {
        $classA1 = $this->makeProduct();
        $classA2 = $this->makeProduct();
        $classB = $this->makeProduct();

        $this->makeReplenishmentRow($classA1, ['abc_class' => 'A', 'revenue_30d' => 1000]);
        $this->makeReplenishmentRow($classA2, ['abc_class' => 'A', 'revenue_30d' => 5000]);
        $this->makeReplenishmentRow($classB, ['abc_class' => 'B', 'revenue_30d' => 9000]);

        $result = $this->service->bestSellers($this->companyId);

        $this->assertCount(2, $result);
        $this->assertSame($classA2, $result[0]['product_id']); // maior faturamento primeiro
        $this->assertSame($classA1, $result[1]['product_id']);
    }

    public function test_liquidation_candidates_returns_excesso_and_estoque_morto_ordered_by_immobilized_value(): void
    {
        $morto = $this->makeProduct();
        $excesso = $this->makeProduct();
        $saudavel = $this->makeProduct();

        $this->makeReplenishmentRow($morto, ['status' => 'estoque_morto', 'immobilized_value' => 9000]);
        $this->makeReplenishmentRow($excesso, ['status' => 'excesso', 'immobilized_value' => 3000]);
        $this->makeReplenishmentRow($saudavel, ['status' => 'saudavel', 'immobilized_value' => 50000]);

        $result = $this->service->liquidationCandidates($this->companyId);

        $ids = collect($result)->pluck('product_id');
        $this->assertCount(2, $result);
        $this->assertSame($morto, $result[0]['product_id']); // maior capital parado primeiro
        $this->assertFalse($ids->contains($saudavel));
    }

    public function test_opportunities_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $otherProduct = DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId, 'sku' => 'OUTRA', 'title' => 'De outra empresa',
            'launched_at' => now()->subDays(5), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = $this->service->launches($this->companyId);

        $this->assertEmpty(collect($result)->where('product_id', $otherProduct));
    }

    public function test_opportunities_returns_empty_arrays_for_company_without_data(): void
    {
        $result = $this->service->opportunities($this->companyId);

        $this->assertSame([], $result['lancamento']);
        $this->assertSame([], $result['mais_vendido']);
        $this->assertSame([], $result['liquidar']);
        $this->assertSame([], $result['perdendo_buybox']);
    }

    public function test_buybox_losses_returns_only_products_losing_buybox_ordered_by_revenue(): void
    {
        $losingHighRevenue = $this->makeProduct(['buybox_winner' => false]);
        $losingLowRevenue = $this->makeProduct(['buybox_winner' => false]);
        $winning = $this->makeProduct(['buybox_winner' => true]);
        $unknown = $this->makeProduct(['buybox_winner' => null]);

        $this->makeReplenishmentRow($losingHighRevenue, ['revenue_30d' => 5000, 'abc_class' => 'A']);
        $this->makeReplenishmentRow($losingLowRevenue, ['revenue_30d' => 100, 'abc_class' => 'C']);
        $this->makeReplenishmentRow($winning, ['revenue_30d' => 9000, 'abc_class' => 'A']);

        $result = $this->service->buyboxLosses($this->companyId);

        $ids = collect($result)->pluck('product_id');
        $this->assertCount(2, $result);
        $this->assertSame($losingHighRevenue, $result[0]['product_id']); // maior faturamento primeiro
        $this->assertSame($losingLowRevenue, $result[1]['product_id']);
        $this->assertFalse($ids->contains($winning));
        $this->assertFalse($ids->contains($unknown));
    }

    public function test_buybox_losses_respects_monitored_flag(): void
    {
        $hidden = $this->makeProduct(['buybox_winner' => false, 'monitored' => false]);
        $visible = $this->makeProduct(['buybox_winner' => false, 'monitored' => true]);

        $result = $this->service->buyboxLosses($this->companyId);

        $ids = collect($result)->pluck('product_id');
        $this->assertTrue($ids->contains($visible));
        $this->assertFalse($ids->contains($hidden));
    }

    public function test_buybox_losses_works_without_replenishment_data(): void
    {
        $productId = $this->makeProduct(['buybox_winner' => false]);

        $result = $this->service->buyboxLosses($this->companyId);

        $row = collect($result)->firstWhere('product_id', $productId);
        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['revenue_30d']);
    }
}
