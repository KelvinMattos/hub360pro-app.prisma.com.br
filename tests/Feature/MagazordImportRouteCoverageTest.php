<?php

namespace Tests\Feature;

use App\Http\Controllers\Magazord\MagazordImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug real: o tipo "inventario" foi adicionado a MagazordImportController::TYPES
 * mas ficou de fora do whereIn('type', [...]) das rotas magazord.show/import em
 * routes/web.php. O Ziggy (roteamento client-side) valida o parâmetro contra
 * esse whereIn e lança exceção JS ao montar o link — e como a sub-navegação
 * do Import.vue gera um link pra CADA tipo (v-for em allTypes), isso quebrou
 * a página de TODOS os tipos (ex.: /imports/magazord/custos), não só a nova.
 *
 * Garante que toda chave de TYPES tem rota funcionando, pra não repetir esse
 * esquecimento na próxima vez que um tipo for adicionado.
 */
class MagazordImportRouteCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_todo_tipo_declarado_em_types_tem_rota_show_registrada(): void
    {
        $user = $this->authenticatedUser();

        foreach (array_keys(MagazordImportController::TYPES) as $type) {
            $response = $this->actingAs($user)->get(route('magazord.show', ['type' => $type]));
            $response->assertOk();
        }
    }
}
