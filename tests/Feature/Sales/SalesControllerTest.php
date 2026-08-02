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

    /**
     * Incidente: "Cliente" em Pedidos Recentes ficava em branco pra pedidos
     * Magazord/Netshoes — o fallback só olhava buyer_nickname (exclusivo
     * Mercado Livre), nunca customer_name (onde esses importadores gravam).
     */
    public function test_recentes_uses_customer_name_column_when_present(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'customer_name' => 'Cliente Magazord Direto', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.cliente', 'Cliente Magazord Direto'));
    }

    /** O CPF normalizado acompanha cada pedido recente, pra "Cliente" virar link pro perfil de consumo. */
    public function test_recentes_includes_normalized_doc_for_customer_link(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'customer_doc' => '111.111.111-11', 'customer_name' => 'Cliente A',
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.doc', '11111111111'));
    }

    public function test_recentes_doc_is_null_when_order_has_no_document(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.doc', null));
    }

    /** Pedido recente inclui o `id` interno do pedido, pra "Pedido" virar link pro detalhe da venda. */
    public function test_recentes_includes_order_id_for_link_to_order_detail(): void
    {
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('recentes.0.id', $orderId));
    }

    /** Range personalizado (from/to) filtra por date_created, não por "hoje - N dias". */
    public function test_index_accepts_custom_date_range(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 500,
            'date_created' => '2026-03-15', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Fora do range pedido — não pode entrar no faturamento.
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 999,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index', ['from' => '2026-03-01', 'to' => '2026-03-31']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('kpis.faturamento', 500)
            ->where('filters.mode', 'range')
        );
    }

    /** Filtro por mês específico (Y-m) cobre o mês inteiro. */
    public function test_index_accepts_month_filter(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 300,
            'date_created' => '2026-02-10', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 700,
            'date_created' => '2026-03-01', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index', ['month' => '2026-02']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('kpis.faturamento', 300)
            ->where('filters.mode', 'month')
            ->where('filters.month', '2026-02')
        );
    }

    /** Data inválida na URL não quebra a página — cai pro modo `days` normal. */
    public function test_index_falls_back_to_days_mode_on_invalid_range(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.index', ['from' => 'not-a-date', 'to' => 'also-not']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->where('filters.mode', 'days'));
    }

    /**
     * Pedido explícito do cliente (01/08/2026): "sempre relacione os pedidos
     * pelo CPF... caso ele tenha comprado em mais de um canal, preciso saber
     * disso." top_clientes marca multicanal=true e lista os canais; o resumo
     * conta quantos clientes do período são multicanal.
     */
    public function test_top_clientes_flags_multichannel_customer_by_shared_cpf(): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'customer_doc' => '222.222.222-22', 'customer_name' => 'Cliente Multicanal',
            'selling_channel' => 'mercadolivre', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 200,
            'billing_doc_number' => '222.222.222-22', 'customer_name' => 'Cliente Multicanal',
            'selling_channel' => 'shopee', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 50,
            'customer_doc' => '333.333.333-33', 'customer_name' => 'Cliente Único',
            'selling_channel' => 'shopee', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('sales.index'));

        $response->assertOk();
        $data = $response->viewData('page')['props'];

        $multicanal = collect($data['top_clientes'])->firstWhere('doc', '22222222222');
        $unico = collect($data['top_clientes'])->firstWhere('doc', '33333333333');

        $this->assertTrue($multicanal['multicanal']);
        $this->assertSame(300.0, $multicanal['total']);
        $this->assertCount(2, $multicanal['canais']);
        $this->assertFalse($unico['multicanal']);

        $this->assertSame(2, $data['clientes_resumo']['total_clientes']);
        $this->assertSame(1, $data['clientes_resumo']['multicanal']);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('sales.index'));
        $response->assertRedirect(route('login'));
    }
}
