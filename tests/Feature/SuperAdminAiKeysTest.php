<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Achados desta auditoria:
 * 1) `system_ai_keys` e `marketplace_benchmark_rates` nunca tiveram migration
 *    — a tela sempre respondia 500 (tabela inexistente).
 * 2) SuperAdminController::index() mandava a api_key INTEIRA como prop
 *    Inertia; o frontend só mostra os últimos 8 caracteres, mas o valor
 *    completo já tinha saído para o navegador.
 */
class SuperAdminAiKeysTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // Auth::id() === 1 é a checagem de acesso do controller.
        $this->user = User::factory()->create(['id' => 1, 'company_id' => $companyId]);
    }

    public function test_index_page_loads_without_500_now_that_tables_exist(): void
    {
        $response = $this->actingAs($this->user)->get(route('admin.keys'));

        $response->assertOk();
    }

    public function test_api_key_is_masked_before_reaching_the_frontend(): void
    {
        DB::table('system_ai_keys')->insert([
            'provider' => 'gemini',
            'api_key' => 'AIzaSySECRETVALUE1234567890',
            'is_active' => true,
            'error_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('admin.keys'));

        $response->assertOk();
        $response->assertInertia(function ($page) {
            $maskedKey = $page->toArray()['props']['keys'][0]['api_key'];
            $this->assertStringEndsWith('34567890', $maskedKey);
            $this->assertStringNotContainsString('AIzaSySECRETVALUE', $maskedKey);
        });
    }

    public function test_non_superadmin_is_forbidden(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Outra Empresa', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $regularUser = User::factory()->create(['id' => 2, 'company_id' => $companyId]);

        $response = $this->actingAs($regularUser)->get(route('admin.keys'));

        $response->assertForbidden();
    }
}
