<?php

namespace Tests\Feature\Sales;

use App\Models\ChannelSalesDaily;
use App\Models\SalesChannelAccount;
use App\Services\Sales\SalesChannelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pedido do cliente (05/08/2026): "Desempenho por Canal" deve ser preenchido
 * automaticamente a partir dos pedidos já importados de cada canal (data,
 * status, pagamento reais) — não deve depender de reimportar manualmente uma
 * informação que os relatórios de canal já trazem. Cobre a regra de
 * combinação: pedido importado sempre vence; o Diário de Vendas manual só
 * preenche o que não tem pedido; nada de um canal desconhecido some do
 * relatório (CLAUDE.md §2.1).
 */
class SalesChannelReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private SalesChannelReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = new SalesChannelReportService();
    }

    private function order(array $overrides = []): int
    {
        static $seq = 0;
        $seq++;

        return DB::table('orders')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'ml_order_id' => 'ORD-' . $seq,
            'status' => 'approved',
            'total_amount' => 100.0,
            'marketplace_fee' => 0.0,
            'shipping_cost' => 0.0,
            'selling_channel' => null,
            'sales_channel_account_id' => null,
            'date_created' => '2026-03-10 10:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_pedido_importado_preenche_o_diario_automaticamente(): void
    {
        $this->order(['selling_channel' => 'Shopee', 'total_amount' => 250.0]);

        $rows = $this->service->daily($this->companyId, null, '2026-03-01', '2026-03-31');

        $this->assertCount(1, $rows);
        $this->assertSame('shopee', $rows[0]['channel']);
        $this->assertSame(250.0, $rows[0]['gross_value']);
        $this->assertSame(250.0, $rows[0]['paid_value']);
        $this->assertSame(1, $rows[0]['orders_count']);
    }

    public function test_conta_cadastrada_distingue_mercado_livre_matriz_de_filial(): void
    {
        $matriz = SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'mercado_livre', 'label' => 'Mercado Livre - Matriz', 'is_active' => true]);
        $filial = SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'mercado_livre', 'label' => 'Mercado Livre - Filial', 'is_active' => true]);

        $this->order(['selling_channel' => 'Mercado Livre', 'sales_channel_account_id' => $matriz->id, 'total_amount' => 300.0]);
        $this->order(['selling_channel' => 'Mercado Livre', 'sales_channel_account_id' => $filial->id, 'total_amount' => 120.0]);

        $summary = $this->service->monthlySummary($this->companyId, 2026);

        $this->assertSame(300.0, $summary['months'][3]['mercado_livre_matriz']['current']['paid_value']);
        $this->assertSame(120.0, $summary['months'][3]['mercado_livre_filial']['current']['paid_value']);
        // Total consolidado do Mercado Livre soma as duas contas.
        $this->assertSame(420.0, $summary['months'][3]['mercado_livre_total']['current']['paid_value']);
    }

    public function test_pedido_cancelado_reduz_pago_mas_conta_no_efetuado(): void
    {
        $this->order(['selling_channel' => 'Centauro', 'total_amount' => 500.0, 'status' => 'approved']);
        $this->order(['selling_channel' => 'Centauro', 'total_amount' => 80.0, 'status' => 'cancelled']);

        $rows = $this->service->daily($this->companyId, 'centauro', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $rows);
        $this->assertSame(580.0, $rows[0]['gross_value']);
        $this->assertSame(80.0, $rows[0]['canceled_value']);
        $this->assertSame(500.0, $rows[0]['paid_value']);
        // Pedido cancelado não conta em "número de pedidos".
        $this->assertSame(1, $rows[0]['orders_count']);
    }

    public function test_diario_manual_so_preenche_dia_canal_sem_pedido_importado(): void
    {
        // Mesmo dia/canal em ambas as fontes — o pedido importado tem que vencer, nunca somar.
        $this->order(['selling_channel' => 'Renner', 'total_amount' => 200.0]);
        ChannelSalesDaily::create([
            'company_id' => $this->companyId, 'channel' => 'renner', 'sale_date' => '2026-03-10',
            'gross_value' => 9999, 'paid_value' => 9999, 'canceled_value' => 0,
            'fees' => 0, 'shipping_cost' => 0, 'net_value' => 9999, 'orders_count' => 40,
        ]);
        // Canal sem importador nativo (Site) — só existe no manual, precisa aparecer.
        ChannelSalesDaily::create([
            'company_id' => $this->companyId, 'channel' => 'site', 'sale_date' => '2026-03-11',
            'gross_value' => 700, 'paid_value' => 650, 'canceled_value' => 50,
            'fees' => 10, 'shipping_cost' => 5, 'net_value' => 635, 'orders_count' => 3,
        ]);

        $rows = collect($this->service->daily($this->companyId, null, '2026-03-01', '2026-03-31'))->keyBy('channel');

        $this->assertSame(200.0, $rows['renner']['gross_value']);
        $this->assertSame(650.0, $rows['site']['paid_value']);
    }

    public function test_canal_nao_reconhecido_cai_em_outros_em_vez_de_sumir(): void
    {
        $this->order(['selling_channel' => 'Canal Novo Que Ninguém Mapeou', 'total_amount' => 150.0]);

        $rows = $this->service->daily($this->companyId, null, '2026-03-01', '2026-03-31');

        $this->assertCount(1, $rows);
        $this->assertSame('outros', $rows[0]['channel']);
        $this->assertSame(150.0, $rows[0]['gross_value']);
    }
}
