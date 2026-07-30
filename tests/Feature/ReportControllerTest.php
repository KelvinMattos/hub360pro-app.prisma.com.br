<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Cliente reportou 500 em /reports. ReportController nunca foi atualizado
 * desde que foi escrito (git log mostra 1 único commit antigo) e assume
 * colunas fixas (`date_created`, `net_profit`, `integration_id`) sem checar
 * se existem — o mesmo padrão de bug documentado em CLAUDE.md §4. `net_profit`
 * em particular é uma coluna recente (migration de 2026-07-29) que pode não
 * ter chegado em produção por causa do bug de deploy já corrigido (PR #9).
 */
class ReportControllerTest extends TestCase
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
            'status' => 'paid',
            'total_amount' => 200,
            'net_profit' => 50,
            'date_created' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_index_loads_successfully_with_real_data(): void
    {
        $user = $this->authenticatedUser();
        $this->insertOrder($user->company_id);

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('currentStats.total_orders', 1)
        );
    }

    /**
     * Reproduz a hipótese mais provável do 500 real: net_profit existe no
     * model/migration mas pode não existir na tabela de produção ainda.
     */
    public function test_index_does_not_crash_when_net_profit_column_is_missing(): void
    {
        $user = $this->authenticatedUser();
        $this->insertOrder($user->company_id);

        Schema::table('orders', function ($table) {
            $table->dropColumn('net_profit');
        });

        $response = $this->actingAs($user)->get(route('reports.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->where('currentStats.profit', 0)
        );
    }

    public function test_export_data_does_not_crash_on_null_date_created(): void
    {
        $user = $this->authenticatedUser();
        // Simula um pedido cuja data não pôde ser parseada na importação (parseDate
        // retorna null) — CLAUDE.md documenta isso como cenário real do importador.
        $this->insertOrder($user->company_id, ['date_created' => null]);

        $response = $this->actingAs($user)->get(route('reports.export'));

        $response->assertOk();
        $this->assertNull($response->json()[0]['Data']);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('reports.index'));
        $response->assertRedirect(route('login'));
    }
}
