<?php

namespace Tests\Feature;

use App\Services\Financial\FinancialProrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CLAUDE.md §5.1 documenta um incidente real: usar created_at (timestamp da
 * importação) em vez de date_created (data real do pedido) jogava vendas
 * antigas no mês da importação. FinancialProrationService tinha exatamente
 * esse bug em calculateNetProfit() e calculateAllocationPerOrder() — o
 * Dashboard e o DRE já tinham sido corrigidos em outro lugar, este serviço não.
 */
class FinancialProrationServiceTest extends TestCase
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

    private function insertOrder(array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => 500,
            'cost_products' => 200,
            'cost_fee_commission' => 20,
            'cost_fee_fixed' => 5,
            'cost_fee_shipping' => 10,
            'cost_fee_ads' => 0,
            'cost_fee_taxes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_calculate_net_profit_uses_date_created_not_import_timestamp(): void
    {
        $realDate = now()->subMonths(7)->startOfMonth()->addDays(3);

        // Pedido real de 7 meses atrás, importado (created_at) só agora.
        $this->insertOrder(['date_created' => $realDate]);

        $service = app(FinancialProrationService::class);

        // Mês da importação (agora) não deve contar o pedido.
        $thisMonth = $service->calculateNetProfit($this->companyId, now()->year, now()->month);
        $this->assertSame(0, $thisMonth['order_count']);
        $this->assertSame(0.0, $thisMonth['gross_revenue']);

        // Mês real do pedido deve contar.
        $realMonth = $service->calculateNetProfit($this->companyId, $realDate->year, $realDate->month);
        $this->assertSame(1, $realMonth['order_count']);
        $this->assertSame(500.0, $realMonth['gross_revenue']);
    }

    public function test_calculate_allocation_per_order_uses_date_created_not_import_timestamp(): void
    {
        $realDate = now()->subMonths(7)->startOfMonth()->addDays(3);
        $this->insertOrder(['date_created' => $realDate]);

        DB::table('fixed_expenses')->insert([
            'company_id' => $this->companyId,
            'description' => 'Aluguel',
            'amount' => 1000,
            'expense_date' => $realDate,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(FinancialProrationService::class);

        // Sem pedido "criado" este mês (pela data real) -> nada a ratear.
        $this->assertSame(0.0, $service->calculateAllocationPerOrder($this->companyId, now()));

        // No mês real do pedido, o custo fixo é rateado sobre o único pedido.
        $this->assertSame(1000.0, $service->calculateAllocationPerOrder($this->companyId, $realDate));
    }

    /**
     * Achado na mesma investigação do bug de Reposição Inteligente: o
     * whitelist de status confirmado não incluía 'approved' — o status que
     * os importadores Magazord e Netshoes gravam pra pedido pago (ver
     * Order::CONFIRMED_STATUSES). Sem isso, a maioria das vendas reais
     * (vindas desses dois canais, não do Mercado Livre) ficava fora do
     * Dashboard financeiro e do DRE.
     */
    public function test_approved_status_from_magazord_netshoes_counts_as_confirmed_sale(): void
    {
        $this->insertOrder(['status' => 'approved', 'total_amount' => 777, 'date_created' => now()]);

        $service = app(FinancialProrationService::class);
        $result = $service->calculateNetProfit($this->companyId, now()->year, now()->month);

        $this->assertSame(1, $result['order_count']);
        $this->assertSame(777.0, $result['gross_revenue']);
    }

    /**
     * A tela de DRE calculava o ponto de equilíbrio como `custo_fixo * 2,5`,
     * um multiplicador inventado sem relação com a margem real da empresa.
     * O correto é custo fixo dividido pela margem de contribuição (% da
     * receita) — quanto pior a margem, mais alto precisa ser o faturamento
     * pra empatar.
     */
    public function test_break_even_revenue_uses_real_contribution_margin_ratio(): void
    {
        $this->insertOrder([
            'total_amount' => 1000, 'cost_products' => 400,
            'cost_fee_commission' => 0, 'cost_fee_fixed' => 0, 'cost_fee_shipping' => 0,
            'date_created' => now(),
        ]);
        DB::table('fixed_expenses')->insert([
            'company_id' => $this->companyId, 'description' => 'Aluguel', 'amount' => 300,
            'expense_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = app(FinancialProrationService::class);
        $result = $service->calculateNetProfit($this->companyId, now()->year, now()->month);

        // Margem de contribuição = 1000 - 400 = 600 (60% da receita).
        // Break-even = 300 / 0.6 = 500.
        $this->assertSame(500.0, $result['break_even_revenue']);
    }

    public function test_break_even_revenue_is_null_without_contribution_margin(): void
    {
        $service = app(FinancialProrationService::class);
        $result = $service->calculateNetProfit($this->companyId, now()->year, now()->month);

        $this->assertNull($result['break_even_revenue']);
    }
}
