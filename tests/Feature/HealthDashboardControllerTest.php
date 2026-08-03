<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A tela DRE Executivo (Financial/DreDashboard.vue) mostrava um "Break Even
 * Point" fabricado (`custo_fixo * 2,5`, sem relação com a margem real) e o
 * histórico de 6 meses usava Carbon::now()->subMonths($i), que estoura em
 * dias que não existem no mês de destino (ex.: dia 31 -> "-1 mês" rola pro
 * mês seguinte em vez do anterior — mesma classe de bug já corrigida em
 * outro lugar do sistema, mas não aqui).
 */
class HealthDashboardControllerTest extends TestCase
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

    public function test_dre_computes_real_break_even_instead_of_fixed_multiplier(): void
    {
        $user = $this->authenticatedUser();

        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 1000,
            'cost_products' => 400, 'cost_fee_commission' => 0, 'cost_fee_fixed' => 0,
            'cost_fee_shipping' => 0, 'cost_fee_ads' => 0, 'cost_fee_taxes' => 0,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('fixed_expenses')->insert([
            'company_id' => $this->companyId, 'description' => 'Aluguel', 'amount' => 300,
            'expense_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('financial.dre'));

        $response->assertOk();
        // Margem de contribuição = 600 (60% da receita) -> break-even = 300 / 0.6 = 500.
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/DreDashboard')
            ->where('indicators.break_even_revenue', 500)
        );
    }

    public function test_dre_history_is_anchored_on_filtered_month_not_on_today(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->get(route('financial.dre', ['month' => '03', 'year' => '2026']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/DreDashboard')
            ->where('indicators.period.month', 3)
            ->where('indicators.period.year', 2026)
            // history[5] é o próprio mês filtrado (março/2026); history[0] é 5 meses antes (outubro/2025).
            ->where('history.5.period.month', 3)
            ->where('history.5.period.year', 2026)
            ->where('history.0.period.month', 10)
            ->where('history.0.period.year', 2025)
        );
    }

    /**
     * Mesmo relato do cliente já corrigido no Painel CFO, agora reportado no
     * DRE Executivo também: sem pedido confirmado no mês corrente e sem
     * filtro explícito, a tela aparecia zerada mesmo com meses anteriores
     * cheios de dado real.
     */
    public function test_dre_falls_back_to_last_month_with_orders_when_current_month_is_empty(): void
    {
        $user = $this->authenticatedUser();

        $realDate = now()->subMonthsNoOverflow(2)->startOfMonth()->addDays(3);
        DB::table('orders')->insert([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 900,
            'date_created' => $realDate, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('financial.dre'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/DreDashboard')
            ->where('indicators.gross_revenue', 900)
            ->where('autoFallback', true)
            ->where('filters.month', (int) $realDate->format('m'))
            ->where('filters.year', (int) $realDate->format('Y'))
        );
    }

    /** Filtro explícito de mês/ano nunca aciona o fallback automático, mesmo vazio. */
    public function test_dre_accepts_explicit_month_filter_without_triggering_fallback(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->get(route('financial.dre', ['month' => '03', 'year' => '2026']));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Financial/DreDashboard')
            ->where('autoFallback', false)
            ->where('filters.month', 3)
            ->where('filters.year', 2026)
        );
    }
}
