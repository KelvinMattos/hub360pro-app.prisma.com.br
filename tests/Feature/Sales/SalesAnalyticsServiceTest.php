<?php

namespace Tests\Feature\Sales;

use App\Services\Sales\SalesAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private SalesAnalyticsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = app(SalesAnalyticsService::class);
    }

    private function insertOrder(array $overrides = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => 100,
            'selling_channel' => 'mercadolivre',
            'date_created' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_kpis_only_counts_confirmed_statuses(): void
    {
        $this->insertOrder(['status' => 'paid', 'total_amount' => 100]);
        $this->insertOrder(['status' => 'pending', 'total_amount' => 999]);
        $this->insertOrder(['status' => 'cancelled', 'total_amount' => 50]);

        $kpis = $this->service->kpis($this->companyId, 30);

        $this->assertSame(100.0, $kpis['faturamento']);
        $this->assertSame(1, $kpis['pedidos']);
        $this->assertSame(1, $kpis['cancelados']);
        $this->assertSame(50.0, $kpis['cancelado_valor']);
    }

    public function test_kpis_uses_date_created_not_import_timestamp(): void
    {
        // Pedido real de 6 meses atrás, "importado" (created_at) só agora —
        // CLAUDE.md §5.1: usar created_at aqui jogaria a venda no período errado.
        $this->insertOrder(['date_created' => now()->subMonths(6), 'created_at' => now(), 'total_amount' => 300]);

        $kpis = $this->service->kpis($this->companyId, 30);

        $this->assertSame(0.0, $kpis['faturamento']);
        $this->assertSame(0, $kpis['pedidos']);
    }

    public function test_kpis_computes_variacao_pct_against_previous_period(): void
    {
        $this->insertOrder(['date_created' => now()->subDays(5), 'total_amount' => 200]);
        $this->insertOrder(['date_created' => now()->subDays(40), 'total_amount' => 100]);

        $kpis = $this->service->kpis($this->companyId, 30);

        $this->assertSame(200.0, $kpis['faturamento']);
        $this->assertSame(100.0, $kpis['variacao_pct']); // dobrou frente ao período anterior
    }

    public function test_kpis_variacao_pct_is_null_without_previous_period_data(): void
    {
        $this->insertOrder(['date_created' => now()->subDays(5), 'total_amount' => 200]);

        $kpis = $this->service->kpis($this->companyId, 30);

        $this->assertNull($kpis['variacao_pct']);
    }

    public function test_por_canal_groups_and_orders_by_total(): void
    {
        $this->insertOrder(['selling_channel' => 'mercadolivre', 'total_amount' => 100]);
        $this->insertOrder(['selling_channel' => 'mercadolivre', 'total_amount' => 50]);
        $this->insertOrder(['selling_channel' => 'shopee', 'total_amount' => 500]);

        $result = $this->service->porCanal($this->companyId, 30);

        $this->assertSame('shopee', $result[0]['canal']);
        $this->assertSame(500.0, $result[0]['total']);
        $this->assertSame('mercadolivre', $result[1]['canal']);
        $this->assertSame(150.0, $result[1]['total']);
        $this->assertSame(2, $result[1]['pedidos']);
    }

    public function test_tendencia_mensal_zero_fills_months_without_sales(): void
    {
        $this->insertOrder(['date_created' => now()->startOfMonth(), 'total_amount' => 300]);

        $result = $this->service->tendenciaMensal($this->companyId, 6);

        $this->assertCount(6, $result);
        $this->assertSame(now()->startOfMonth()->format('Y-m'), $result[5]['mes']);
        $this->assertSame(300.0, $result[5]['total']);
        $this->assertSame(0.0, $result[0]['total']);
    }

    public function test_tendencia_mensal_respects_date_created_over_created_at(): void
    {
        $this->insertOrder(['date_created' => now()->subMonths(3)->startOfMonth()->addDay(), 'created_at' => now(), 'total_amount' => 400]);

        $result = $this->service->tendenciaMensal($this->companyId, 6);

        $threeMonthsAgoKey = now()->subMonths(3)->startOfMonth()->format('Y-m');
        $currentKey = now()->startOfMonth()->format('Y-m');
        $bucket = collect($result)->firstWhere('mes', $threeMonthsAgoKey);
        $currentBucket = collect($result)->firstWhere('mes', $currentKey);

        $this->assertSame(400.0, $bucket['total']);
        $this->assertSame(0.0, $currentBucket['total']);
    }

    public function test_por_regiao_estado_joins_customers_and_ignores_empty_state(): void
    {
        $spCustomer = DB::table('customers')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Cliente SP', 'state' => 'SP', 'city' => 'São Paulo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $rjCustomer = DB::table('customers')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Cliente RJ', 'state' => 'RJ', 'city' => 'Rio de Janeiro',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $noStateCustomer = DB::table('customers')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Sem estado', 'state' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->insertOrder(['customer_id' => $spCustomer, 'total_amount' => 700]);
        $this->insertOrder(['customer_id' => $rjCustomer, 'total_amount' => 300]);
        $this->insertOrder(['customer_id' => $noStateCustomer, 'total_amount' => 999]);

        $result = $this->service->porRegiaoEstado($this->companyId, 30);

        $this->assertCount(2, $result);
        $this->assertSame('SP', $result[0]['estado']);
        $this->assertSame(700.0, $result[0]['total']);
        $this->assertSame('RJ', $result[1]['estado']);
    }

    public function test_por_regiao_macro_buckets_known_ufs_and_unknown_as_nao_identificado(): void
    {
        $porEstado = [
            ['estado' => 'SP', 'total' => 1000.0, 'pedidos' => 5],
            ['estado' => 'BA', 'total' => 500.0, 'pedidos' => 2],
            ['estado' => 'RS', 'total' => 300.0, 'pedidos' => 1],
            ['estado' => 'São Paulo', 'total' => 200.0, 'pedidos' => 1], // formato não reconhecido, não deve virar Sudeste por adivinhação
        ];

        $result = $this->service->porRegiaoMacro($porEstado);

        $byRegiao = collect($result)->keyBy('regiao');
        $this->assertSame(1000.0, $byRegiao['Sudeste']['total']);
        $this->assertSame(500.0, $byRegiao['Nordeste']['total']);
        $this->assertSame(300.0, $byRegiao['Sul']['total']);
        $this->assertSame(200.0, $byRegiao['Não identificado']['total']);
    }

    public function test_por_marca_groups_via_order_items_and_products(): void
    {
        $orderId = $this->insertOrder(['total_amount' => 300]);
        $productA = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'title' => 'Produto A', 'sku' => 'A1', 'brand' => 'Nike',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productB = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'title' => 'Produto B', 'sku' => 'B1', 'brand' => 'Adidas',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            ['order_id' => $orderId, 'product_id' => $productA, 'sku' => 'A1', 'title' => 'Produto A', 'quantity' => 2, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $orderId, 'product_id' => $productB, 'sku' => 'B1', 'title' => 'Produto B', 'quantity' => 1, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->service->porMarca($this->companyId, 30);

        $this->assertSame('Nike', $result[0]['marca']);
        $this->assertSame(200.0, $result[0]['total']);
        $this->assertSame(2, $result[0]['unidades']);
        $this->assertSame('Adidas', $result[1]['marca']);
    }

    public function test_top_produtos_groups_by_sku_ordered_by_revenue(): void
    {
        $orderId = $this->insertOrder(['total_amount' => 300]);
        DB::table('order_items')->insert([
            ['order_id' => $orderId, 'sku' => 'X1', 'title' => 'Best Seller', 'quantity' => 3, 'unit_price' => 100, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $orderId, 'sku' => 'X2', 'title' => 'Menos vendido', 'quantity' => 1, 'unit_price' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $result = $this->service->topProdutos($this->companyId, 30);

        $this->assertSame('Best Seller', $result[0]['titulo']);
        $this->assertSame(300.0, $result[0]['total']);
        $this->assertSame(3, $result[0]['unidades']);
    }

    public function test_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert([
            'company_id' => $otherCompanyId, 'status' => 'paid', 'total_amount' => 5000,
            'selling_channel' => 'shopee', 'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $kpis = $this->service->kpis($this->companyId, 30);
        $canal = $this->service->porCanal($this->companyId, 30);

        $this->assertSame(0.0, $kpis['faturamento']);
        $this->assertSame([], $canal);
    }

    public function test_schema_ready_true_when_orders_table_has_total_amount(): void
    {
        $this->assertTrue($this->service->schemaReady());
    }
}
