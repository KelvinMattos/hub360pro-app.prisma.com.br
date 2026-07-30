<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CLAUDE.md §5.1: date_created é a data real do pedido; created_at é o
 * timestamp da importação. O "Volume de Vendas (30 dias)" do simulador de
 * preço usava created_at — um pedido antigo reimportado agora entraria como
 * "vendido nos últimos 30 dias", e um pedido recente cujo created_at ficou
 * velho (reprocessamento em lote) sumiria da contagem.
 */
class PricingSimulationDateBugTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);

        $this->product = Product::create([
            'company_id' => $this->companyId,
            'title' => 'Produto Teste',
            'sku' => 'SKU-1',
            'price' => 100,
            'sale_price' => 100,
            'cost_price' => 40,
            'stock_quantity' => 10,
            'status' => 'active',
        ]);
    }

    private function insertOrderWithItem(array $orderOverrides): void
    {
        $orderId = DB::table('orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ], $orderOverrides));

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_old_order_reimported_recently_is_not_counted_as_recent_sale(): void
    {
        // Pedido de 6 meses atrás, mas cujo created_at (importação) é de agora.
        $this->insertOrderWithItem(['date_created' => now()->subMonths(6), 'created_at' => now()]);

        $response = $this->actingAs($this->user)->postJson(route('pricing.simulate'), [
            'product_id' => $this->product->id,
            'price_change_percent' => 0,
            'ads_change_percent' => 0,
        ]);

        $response->assertOk();
        $this->assertSame(0, $response->json('current.volume'));
    }

    public function test_recent_order_with_stale_import_timestamp_is_counted(): void
    {
        // Pedido de ontem (data real), mas created_at antigo (reprocessamento em lote).
        $this->insertOrderWithItem(['date_created' => now()->subDay(), 'created_at' => now()->subMonths(6)]);

        $response = $this->actingAs($this->user)->postJson(route('pricing.simulate'), [
            'product_id' => $this->product->id,
            'price_change_percent' => 0,
            'ads_change_percent' => 0,
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('current.volume'));
    }
}
