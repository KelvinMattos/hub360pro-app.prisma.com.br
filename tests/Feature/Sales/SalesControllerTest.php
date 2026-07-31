<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesControllerTest extends TestCase
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

    public function test_index_renders_full_sales_dashboard(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 250,
            'selling_channel' => 'mercadolivre', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Index')
            ->where('has_data', true)
            ->where('kpis.faturamento', 250)
            ->has('mensal', 12)
            ->has('por_canal', 1)
        );
    }

    public function test_index_returns_empty_state_without_data(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Sales/Index')
            ->where('has_data', false)
            ->where('kpis.faturamento', 0)
        );
    }

    public function test_index_accepts_days_filter(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.index', ['days' => 90]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('days', 90));
    }

    public function test_index_falls_back_to_default_days_for_invalid_value(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.index', ['days' => 999]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('days', 30));
    }

    /**
     * Incidente: pedidos importados via Magazord/CSV nunca preenchem
     * ml_order_id (campo exclusivo da API do Mercado Livre) — como a coluna
     * existe no schema, o fallback antigo escolhia ela mesmo assim e a
     * coluna "Pedido" ficava em branco pra esse tipo de pedido.
     */
    public function test_recentes_falls_back_to_internal_id_when_ml_order_id_is_null(): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'ml_order_id' => null, 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.pedido', (string) $orderId));
    }

    /** Nome do cliente vem de customers.name (via customer_id) quando não há buyer_nickname (ex: Magazord). */
    public function test_recentes_uses_customer_name_from_customers_table(): void
    {
        $customerId = DB::table('customers')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Cliente Magazord', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100, 'customer_id' => $customerId,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.cliente', 'Cliente Magazord'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('sales.index'));
        $response->assertRedirect(route('login'));
    }
}
