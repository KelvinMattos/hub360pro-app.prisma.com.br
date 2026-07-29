<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * CLAUDE.md §5.1 documenta um incidente real: a Análise de Vendas agrupava
 * por created_at (timestamp da importação) em vez de date_created (data real
 * do pedido), jogando o ano inteiro no mês da importação. O resumo de vendas
 * do Dashboard tinha a mesma prioridade invertida — este teste prova que um
 * pedido antigo importado hoje não aparece no "últimos 30 dias".
 */
class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_summary_uses_date_created_not_import_timestamp(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create(['company_id' => $companyId]);

        // Pedido real de 7 meses atrás, importado (created_at) só agora.
        DB::table('orders')->insert([
            'company_id' => $companyId,
            'status' => 'paid',
            'total_amount' => 500,
            'date_created' => now()->subMonths(7),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('ManagementDashboard')
            ->where('sales.rev30', 0)
            ->where('sales.orders30', 0)
            ->where('sales.total_pedidos', 1)
        );
    }
}
