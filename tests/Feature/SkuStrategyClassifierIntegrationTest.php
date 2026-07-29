<?php

namespace Tests\Feature;

use App\Services\SkuStrategyClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Testa a orquestração completa de SkuStrategyClassifier::classifyCompany()
 * contra o schema real (produtos + pedidos), não só as funções puras.
 */
class SkuStrategyClassifierIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'sku' => 'SKU-' . uniqid(),
            'title' => 'Produto Teste',
            'sale_price' => 100.00,
            'cost_price' => 50.00,
            'stock_quantity' => 20,
            'launched_at' => now()->subYear(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertSale(int $productId, Carbon $date, int $qty = 1): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => 100 * $qty,
            'date_created' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'sku' => 'x',
            'quantity' => $qty,
            'unit_price' => 100,
            'unit_cost' => 50,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_classify_company_upserts_one_row_per_product(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        $count = app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $this->assertSame(2, $count);
        $this->assertDatabaseHas('sku_strategy', ['product_id' => $p1, 'company_id' => $this->companyId]);
        $this->assertDatabaseHas('sku_strategy', ['product_id' => $p2, 'company_id' => $this->companyId]);
    }

    public function test_classify_company_is_idempotent_via_upsert(): void
    {
        $productId = $this->makeProduct();

        app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);
        app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $this->assertSame(1, DB::table('sku_strategy')->where('product_id', $productId)->count());
    }

    public function test_classify_company_computes_real_volume_and_margin_from_orders(): void
    {
        $highMarginHighVolume = $this->makeProduct(['sale_price' => 200, 'cost_price' => 50]); // 75% margem
        $lowMarginLowVolume = $this->makeProduct(['sale_price' => 100, 'cost_price' => 90]);    // 10% margem

        // Alto volume para o produto de margem alta.
        for ($i = 0; $i < 10; $i++) {
            $this->insertSale($highMarginHighVolume, now()->subDays(5));
        }
        // Baixo volume para o produto de margem baixa.
        $this->insertSale($lowMarginLowVolume, now()->subDays(5));

        app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $rowHigh = DB::table('sku_strategy')->where('product_id', $highMarginHighVolume)->first();
        $rowLow = DB::table('sku_strategy')->where('product_id', $lowMarginLowVolume)->first();

        $this->assertSame(75.0, (float) $rowHigh->margin_pct);
        $this->assertSame(10, $rowHigh->volume_30d);
        $this->assertSame('estrela', $rowHigh->pricing_role);

        $this->assertSame(10.0, (float) $rowLow->margin_pct);
        $this->assertSame(1, $rowLow->volume_30d);
        $this->assertSame('reavaliar', $rowLow->pricing_role);
    }

    public function test_classify_company_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Outra Empresa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId,
            'sku' => 'OUTRA',
            'title' => 'Produto de outra empresa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeProduct();

        $count = app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $this->assertSame(1, $count);
        $this->assertSame(0, DB::table('sku_strategy')->where('company_id', $otherCompanyId)->count());
    }

    public function test_classify_company_returns_zero_for_company_without_products(): void
    {
        $emptyCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Vazia', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = app(SkuStrategyClassifier::class)->classifyCompany($emptyCompanyId);

        $this->assertSame(0, $count);
    }
}
