<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Inventory\ReplenishmentEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * /inventory/planning ("Reposição Inteligente") reescrito: antes mandava 78
 * mil linhas de uma vez num prop do Inertia (~19MB, travava o navegador).
 * Agora só lê `replenishment_plan`, paginada e filtrada no banco.
 */
class ReplenishmentControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);
    }

    public function test_index_shows_empty_state_before_first_computation(): void
    {
        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Inventory/Replenishment')
            ->where('has_data', false)
            ->where('rows', [])
        );
    }

    public function test_index_paginates_instead_of_dumping_everything_at_once(): void
    {
        for ($i = 0; $i < 5; $i++) {
            DB::table('products')->insertGetId([
                'company_id' => $this->companyId, 'sku' => "SKU-$i", 'title' => "Produto $i",
                'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        app(ReplenishmentEngine::class)->computeCompany($this->companyId);

        $response = $this->actingAs($this->user)->get(route('inventory.planning', ['tab' => 'todos']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('has_data', true)
            ->where('total', 5)
            ->where('per_page', 50)
        );
    }

    /** O bug relatado pelo cliente: velocity zerada em 100% das linhas, coverage sempre 999, status sempre "healthy". */
    public function test_ruptura_products_show_real_velocity_and_never_999_days(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'ABC', 'title' => 'Produto Vendendo',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'approved', 'total_amount' => 300,
            'date_created' => now()->subDays(2), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'sku' => 'x',
            'quantity' => 3, 'unit_price' => 100, 'unit_cost' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ReplenishmentEngine::class)->computeCompany($this->companyId);

        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.status', 'ruptura')
            ->where('rows.0.stock', 0)
        );

        $row = DB::table('replenishment_plan')->where('product_id', $productId)->first();
        $this->assertNotEquals(999, $row->coverage_days);
        $this->assertGreaterThan(0, (float) $row->velocity_weighted);
    }

    public function test_default_tab_excludes_descontinuado(): void
    {
        DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'DEAD', 'title' => 'Fora do radar',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ReplenishmentEngine::class)->computeCompany($this->companyId);

        $response = $this->actingAs($this->user)->get(route('inventory.planning'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('total', 0));
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get(route('inventory.planning'));
        $response->assertRedirect(route('login'));
    }

    /** Pedido do cliente (01/08/2026): prioridade vira % relativo ao SKU mais urgente, não número cru. */
    public function test_priority_is_exposed_as_percentage_relative_to_max(): void
    {
        $urgent = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'URG', 'title' => 'Urgente',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'approved', 'total_amount' => 500,
            'date_created' => now()->subDays(1), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $urgent, 'sku' => 'x',
            'quantity' => 5, 'unit_price' => 100, 'unit_cost' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'CALM', 'title' => 'Saudável',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 500,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(ReplenishmentEngine::class)->computeCompany($this->companyId);

        $response = $this->actingAs($this->user)->get(route('inventory.planning', ['tab' => 'todos']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('rows.0.priority_pct', 100)
        );
        foreach ($response->viewData('page')['props']['rows'] as $row) {
            $this->assertGreaterThanOrEqual(0, $row['priority_pct']);
            $this->assertLessThanOrEqual(100, $row['priority_pct']);
        }
    }

    public function test_sales_endpoint_returns_history_with_profit_and_full_price_flag(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'HIST', 'title' => 'Produto Histórico',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderFull = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'approved', 'total_amount' => 100,
            'date_created' => now()->subDays(3), 'selling_channel' => 'site',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderFull, 'product_id' => $productId, 'sku' => 'x',
            'quantity' => 1, 'unit_price' => 100, 'unit_cost' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $orderClearance = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'approved', 'total_amount' => 52,
            'date_created' => now()->subDays(1), 'selling_channel' => 'site',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderClearance, 'product_id' => $productId, 'sku' => 'x',
            'quantity' => 1, 'unit_price' => 52, 'unit_cost' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->getJson(route('inventory.planning.sales', $productId));

        $response->assertOk();
        $data = $response->json();
        $this->assertSame('HIST', $data['product']['sku']);
        $this->assertCount(2, $data['sales']);
        $flags = array_column($data['sales'], 'is_full_price');
        $this->assertContains(true, $flags);
        $this->assertContains(false, $flags);
        $this->assertSame(2, $data['summary']['total_qty']);
    }

    public function test_sales_endpoint_requires_authentication(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'X', 'title' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson(route('inventory.planning.sales', $productId))->assertStatus(401);
    }

    public function test_sales_endpoint_blocks_product_from_other_company(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Outra', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = DB::table('products')->insertGetId([
            'company_id' => $otherCompanyId, 'sku' => 'ALHEIO', 'title' => 'De outra empresa',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->getJson(route('inventory.planning.sales', $productId))->assertStatus(404);
    }
}
