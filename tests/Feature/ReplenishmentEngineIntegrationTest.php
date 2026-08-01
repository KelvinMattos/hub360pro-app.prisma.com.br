<?php

namespace Tests\Feature;

use App\Services\Inventory\ReplenishmentEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Testa ReplenishmentEngine::computeCompany() contra o schema real (produtos +
 * pedidos confirmados), cobrindo os casos de borda pedidos explicitamente:
 * velocidade 0, estoque 0 (com e sem venda), produto novo sem histórico e
 * produto que ficou sem estoque no meio do período.
 */
class ReplenishmentEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private ReplenishmentEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->engine = app(ReplenishmentEngine::class);
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
            'lead_time' => 15,
            'moq' => 1,
            'purchase_multiple' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /** Venda a preço cheio por padrão (100/50 — margem saudável, qualifica no motor). */
    private function insertSale(int $productId, Carbon $date, int $qty = 1): void
    {
        $this->insertSaleAtPrice($productId, $date, $qty, 100, 50);
    }

    private function insertSaleAtPrice(int $productId, Carbon $date, int $qty, float $unitPrice, float $unitCost): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId,
            'status' => 'approved',
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

    public function test_computes_one_row_per_product_and_is_idempotent(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        $count = $this->engine->computeCompany($this->companyId);
        $this->assertSame(2, $count);

        $this->engine->computeCompany($this->companyId);
        $this->assertSame(1, DB::table('replenishment_plan')->where('product_id', $p1)->count());
        $this->assertSame(1, DB::table('replenishment_plan')->where('product_id', $p2)->count());
    }

    /** Caso de borda explícito: velocidade 0 nunca pode virar 999 dias — sempre null ("sem giro"). */
    public function test_zero_velocity_never_produces_999_days_coverage(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 50]);
        // sem nenhuma venda

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(0.0, (float) $row->velocity_weighted);
        $this->assertNull($row->coverage_days);
        $this->assertNotEquals(999, $row->coverage_days);
    }

    /** Caso de borda explícito: estoque 0 com venda recente = RUPTURA (compra urgente), nunca "healthy". */
    public function test_zero_stock_with_sales_is_ruptura(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 0]);
        $this->insertSale($productId, now()->subDays(2), 3);

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(ReplenishmentEngine::STATUS_RUPTURA, $row->status);
        $this->assertGreaterThan(0, $row->suggested_qty);
    }

    /** Caso de borda explícito: estoque 0 sem NENHUMA venda = DESCONTINUADO, filtrado por padrão da lista de ação. */
    public function test_zero_stock_without_sales_is_descontinuado(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 0]);
        // sem nenhuma venda

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(ReplenishmentEngine::STATUS_DESCONTINUADO, $row->status);
        $this->assertSame(0, $row->suggested_qty);
    }

    /** Caso de borda explícito: produto novo, recém-lançado, sem histórico — não pode virar "estoque morto". */
    public function test_brand_new_product_without_history_is_not_dead_stock(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 30, 'launched_at' => now()->subDays(2)]);
        // sem nenhuma venda ainda — acabou de ser lançado

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(ReplenishmentEngine::STATUS_SAUDAVEL, $row->status);
        $this->assertNotSame(ReplenishmentEngine::STATUS_ESTOQUE_MORTO, $row->status);
    }

    /** Um produto antigo, sem lançamento recente, com estoque e zero venda em 90d é sim estoque morto. */
    public function test_old_product_with_stock_and_no_recent_sales_is_dead_stock(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 30, 'launched_at' => now()->subYears(2)]);

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(ReplenishmentEngine::STATUS_ESTOQUE_MORTO, $row->status);
    }

    /**
     * Caso de borda explícito: produto que vendia bem e ficou sem estoque no
     * meio do período de 30 dias — a velocidade não pode ser artificialmente
     * diluída pela divisão pelo calendário cheio (o "erro clássico").
     */
    public function test_velocity_not_diluted_when_product_ran_out_of_stock_mid_window(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 0]);
        // Vendeu 1 unidade/dia nos primeiros 10 dias da janela de 30 e parou (ficou sem estoque).
        for ($i = 29; $i >= 20; $i--) {
            $this->insertSale($productId, now()->subDays($i), 1);
        }

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        // Se dividisse pelos 30 dias cheios, a velocidade cairia para ~0.33/dia.
        // Usando o span de venda real (~10 dias), fica perto de 1/dia.
        $this->assertGreaterThan(0.5, (float) $row->velocity_30);
    }

    public function test_confirmed_status_approved_from_magazord_netshoes_counts_towards_velocity(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 100]);
        $this->insertSale($productId, now()->subDays(2), 3); // status 'approved' no helper

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertGreaterThan(0, (float) $row->velocity_weighted);
    }

    public function test_suggested_quantity_respects_minimum_order_quantity_and_multiple(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 0, 'moq' => 50, 'purchase_multiple' => 12]);
        $this->insertSale($productId, now()->subDays(1), 2);

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertGreaterThanOrEqual(50, $row->suggested_qty);
        $this->assertSame(0, $row->suggested_qty % 12);
    }

    public function test_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Outra Empresa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId,
            'sku' => 'OUTRA', 'title' => 'De outra empresa',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeProduct();

        $count = $this->engine->computeCompany($this->companyId);

        $this->assertSame(1, $count);
        $this->assertSame(0, DB::table('replenishment_plan')->where('company_id', $otherCompanyId)->count());
    }

    /**
     * Pedido do cliente (01/08/2026): venda de queima (preço colado no custo,
     * bem abaixo da meta lucro) não pode alimentar giro nem gerar prioridade
     * de reposição. Custo 50, preço 52 — margem de ~4%, longe da meta (~23%
     * de markup sobre o equilíbrio ~76 = meta ~94 com a config padrão).
     */
    public function test_clearance_priced_sales_do_not_count_towards_velocity(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 0]);
        $this->insertSaleAtPrice($productId, now()->subDays(2), 10, 52, 50);

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertSame(0.0, (float) $row->velocity_weighted);
        // Sem giro qualificado e sem estoque: não é RUPTURA (não tem venda "que conte"), é descontinuado.
        $this->assertSame(ReplenishmentEngine::STATUS_DESCONTINUADO, $row->status);
        $this->assertSame(10, (int) $row->qty_clearance_30);
    }

    /** Mistura: só a parte vendida a preço cheio deve aparecer na velocidade. */
    public function test_mixed_sales_only_full_price_portion_counts_towards_velocity(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 20]);
        $this->insertSaleAtPrice($productId, now()->subDays(1), 7, 100, 50); // preço cheio, qualifica
        $this->insertSaleAtPrice($productId, now()->subDays(1), 4, 51, 50);  // queima, não qualifica

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        // Estoque > 0 => janela cheia de 30d; só as 7 unidades a preço cheio entram no giro.
        $this->assertEqualsWithDelta(7 / 30, (float) $row->velocity_30, 0.01);
        $this->assertSame(4, (int) $row->qty_clearance_30);
        $this->assertGreaterThan(0, (float) $row->velocity_weighted);
    }

    /**
     * Quando o pedido já tem o encargo REAL (cost_fee_commission/taxes/
     * shipping/fixed — hoje só ML sincronizado via API), o motor usa esse
     * valor em vez da média de canais — resposta à pergunta "como ligar o
     * pedido ao canal exato" (01/08/2026). Encargo real de 50% aqui faz a
     * meta lucro subir bem acima do preço praticado, mesmo preço/custo que
     * QUALIFICARIA com a média padrão da empresa (~35%).
     */
    public function test_uses_real_order_level_fees_over_company_average_when_available(): void
    {
        $productId = $this->makeProduct(['stock_quantity' => 20]);
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'approved',
            'total_amount' => 200, // 2 un x 100
            'cost_fee_commission' => 80, 'cost_fee_taxes' => 20,
            'cost_fee_shipping' => 0, 'cost_fee_fixed' => 0, // soma = 100 -> encargo real = 50%
            'date_created' => now()->subDays(1),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'sku' => 'x',
            'quantity' => 2, 'unit_price' => 100, 'unit_cost' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->engine->computeCompany($this->companyId);

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        // Com encargo real de 50%, meta lucro (~123) > preço praticado (100) -> não qualifica.
        $this->assertSame(0.0, (float) $row->velocity_weighted);
        $this->assertSame(2, (int) $row->qty_clearance_30);
    }

    public function test_returns_zero_for_company_without_products(): void
    {
        $emptyCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Vazia', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $count = $this->engine->computeCompany($emptyCompanyId);

        $this->assertSame(0, $count);
    }
}
