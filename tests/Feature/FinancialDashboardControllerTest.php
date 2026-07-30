<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O card "Faturamento Bruto" mostrava "+12.5% vs mês ant." fixo, e o texto
 * "Sua margem está 4% acima da média do setor" era 100% inventado (não existe
 * fonte de dado de setor). Este teste prova que os dois agora vêm de cálculo
 * real sobre os pedidos, com "—"/null quando não há base de comparação —
 * nunca um valor inventado ou uma divisão por zero.
 */
class FinancialDashboardControllerTest extends TestCase
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

    private function authenticatedUser(): User
    {
        return User::factory()->create(['company_id' => $this->companyId]);
    }

    private function insertOrder(Carbon $date, float $totalAmount, float $costProducts): void
    {
        DB::table('orders')->insert([
            'company_id' => $this->companyId,
            'status' => 'paid',
            'total_amount' => $totalAmount,
            'cost_products' => $costProducts,
            'cost_fee_commission' => 0,
            'cost_fee_fixed' => 0,
            'cost_fee_shipping' => 0,
            'cost_fee_ads' => 0,
            'cost_fee_taxes' => 0,
            // date_created é a data real do pedido; created_at fica "agora" (timestamp
            // de importação) para provar que o cálculo usa a data certa (CLAUDE.md §5.1).
            'date_created' => $date,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_computes_real_growth_and_margin_delta_from_orders(): void
    {
        $user = $this->authenticatedUser();

        // Mês anterior: receita 500, custo produto 100 => contrib. margin 400 (80%).
        $this->insertOrder(now()->subMonthNoOverflow()->startOfMonth()->addDays(2), 500, 100);
        // Mês atual: receita 1000, custo produto 300 => contrib. margin 700 (70%).
        $this->insertOrder(now()->startOfMonth()->addDays(2), 1000, 300);

        $response = $this->actingAs($user)->get(route('financial.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/Dashboard')
            ->where('stats.grossRevenue', 1000)
            ->where('stats.contributionMargin', 70)
            // (1000 - 500) / 500 * 100 = 100%
            ->where('revenueGrowthPct', 100)
            // 70% (mês atual) - 80% (única referência histórica) = -10 p.p.
            ->where('marginDeltaVsHistoryPct', -10)
        );
    }

    public function test_index_returns_null_growth_and_margin_without_historical_data(): void
    {
        $user = $this->authenticatedUser();

        // Nenhum pedido em nenhum mês — sem base real de comparação.
        $response = $this->actingAs($user)->get(route('financial.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/Dashboard')
            ->where('stats.grossRevenue', 0)
            ->where('stats.contributionMargin', 0) // guarda de divisão por zero, nunca NaN
            ->where('revenueGrowthPct', null)
            ->where('marginDeltaVsHistoryPct', null)
        );
    }
}
