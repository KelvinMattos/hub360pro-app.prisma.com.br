<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Achados na mesma investigação do bug de Reposição Inteligente: este
 * dashboard tinha os 3 problemas de uma vez —
 * 1) growth_percent hardcoded em 12.5 (nunca calculado);
 * 2) whereDate('created_at', ...) em vez de date_created (CLAUDE.md §5.1);
 * 3) whitelist de status sem 'approved' (Order::CONFIRMED_STATUSES).
 */
class MarketplaceDashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function insertOrder(int $companyId, array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'company_id' => $companyId,
            'status' => 'approved',
            'total_amount' => 100,
            'date_created' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_growth_percent_is_computed_from_real_sales_not_hardcoded(): void
    {
        $user = $this->authenticatedUser();
        // Ontem: 100. Hoje: 150. Crescimento real = 50%.
        $this->insertOrder($user->company_id, ['total_amount' => 100, 'date_created' => now()->subDay()]);
        $this->insertOrder($user->company_id, ['total_amount' => 150, 'date_created' => now()]);

        $response = $this->actingAs($user)->get(route('marketplaces.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Marketplace/Dashboard')
            ->where('stats.growth_percent', 50)
            ->where('stats.sales_today', 150)
        );
    }

    public function test_growth_percent_is_null_without_yesterday_data_never_a_fake_number(): void
    {
        $user = $this->authenticatedUser();
        $this->insertOrder($user->company_id, ['total_amount' => 150, 'date_created' => now()]);

        $response = $this->actingAs($user)->get(route('marketplaces.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.growth_percent', null)
        );
    }

    public function test_approved_status_from_magazord_netshoes_counts_in_sales_today(): void
    {
        $user = $this->authenticatedUser();
        $this->insertOrder($user->company_id, ['status' => 'approved', 'total_amount' => 200, 'date_created' => now()]);

        $response = $this->actingAs($user)->get(route('marketplaces.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('stats.sales_today', 200));
    }

    public function test_old_order_reimported_today_is_not_counted_as_sold_today(): void
    {
        $user = $this->authenticatedUser();
        // Pedido real de 6 meses atrás, reimportado (created_at) só agora.
        $this->insertOrder($user->company_id, ['date_created' => now()->subMonths(6), 'created_at' => now(), 'total_amount' => 500]);

        $response = $this->actingAs($user)->get(route('marketplaces.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->where('stats.sales_today', 0));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('marketplaces.dashboard'));
        $response->assertRedirect(route('login'));
    }
}
