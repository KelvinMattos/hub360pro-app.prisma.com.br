<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * InventoryIntelligenceService (tela "Reposição de Estoque",
 * /inventory/planning) tinha 3 problemas reais encontrados nesta auditoria:
 * 1) usava $product->name, mas a coluna real de products é `title` — o nome
 *    do produto aparecia em branco na tela;
 * 2) Product::where(...)->get() carregava o eager-load $with =
 *    ['medias','channel_settings'] do model, o mesmo padrão que já derrubou
 *    a tela de Aging com 500 (CLAUDE.md §4);
 * 3) filtrava vendas dos "últimos 30 dias" por orders.created_at (timestamp
 *    de importação) em vez de date_created (data real do pedido) —
 *    CLAUDE.md §5.1.
 */
class InventoryIntelligenceServiceTest extends TestCase
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
            'title' => 'Tênis Corrida Pro',
            'sku' => 'SKU-1',
            'price' => 200,
            'sale_price' => 200,
            'cost_price' => 80,
            'stock_quantity' => 10,
            'status' => 'active',
        ]);
    }

    private function insertOrderWithItem(array $orderOverrides): void
    {
        $orderId = DB::table('orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ], $orderOverrides));

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => $this->product->id,
            'quantity' => 3,
            'unit_price' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_planning_page_shows_real_product_title_as_name(): void
    {
        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Inventory/Replenishment')
            ->where('inventoryData.0.name', 'Tênis Corrida Pro')
        );
    }

    public function test_planning_page_does_not_crash_with_products_present(): void
    {
        // Antes da correção, Product::where(...)->get() disparava o eager-load
        // medias/channel_settings do model — este teste prova que a tela
        // renderiza normalmente com produto e pedido presentes.
        $this->insertOrderWithItem(['date_created' => now()->subDays(2)]);

        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
    }

    public function test_recent_sales_velocity_uses_date_created_not_import_timestamp(): void
    {
        // Pedido real de 6 meses atrás, reimportado (created_at) só agora.
        $this->insertOrderWithItem(['date_created' => now()->subMonths(6), 'created_at' => now()]);

        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('inventoryData.0.velocity', 0)
        );
    }
}
