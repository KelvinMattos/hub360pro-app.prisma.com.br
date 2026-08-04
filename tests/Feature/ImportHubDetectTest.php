<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Central de Importações: endpoint que SÓ lê o cabeçalho do arquivo (CSV/XLSX)
 * pra detectar o tipo, sem gravar nada no banco — a gravação de verdade
 * continua acontecendo na tela original de cada tipo.
 */
class ImportHubDetectTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_detecta_csv_de_custos_magazord_e_nao_grava_nada(): void
    {
        $user = $this->authenticatedUser();

        $file = new UploadedFile(
            base_path('tests/Fixtures/detect-magazord-custos-sample.csv'), 'custos.csv', 'text/csv', null, true
        );

        $response = $this->actingAs($user)->postJson(route('imports.hub.detect'), ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'confident');
        $response->assertJsonPath('match.source', 'magazord');
        $response->assertJsonPath('match.type', 'custos');

        $this->assertSame(0, DB::table('products')->count());
    }

    public function test_detecta_xlsx_de_inventario_geral(): void
    {
        $user = $this->authenticatedUser();

        $file = new UploadedFile(
            base_path('tests/Fixtures/magazord-inventario-sample.xlsx'), 'inventario.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );

        $response = $this->actingAs($user)->postJson(route('imports.hub.detect'), ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'confident');
        $response->assertJsonPath('match.source', 'magazord');
        $response->assertJsonPath('match.type', 'inventario');
    }

    public function test_arquivo_sem_relacao_e_reportado_como_desconhecido(): void
    {
        $user = $this->authenticatedUser();

        $path = tempnam(sys_get_temp_dir(), 'detect');
        file_put_contents($path, "Coluna Aleatoria;Outra Coisa\nx;y\n");
        $file = new UploadedFile($path, 'aleatorio.csv', 'text/csv', null, true);

        $response = $this->actingAs($user)->postJson(route('imports.hub.detect'), ['file' => $file]);

        $response->assertOk();
        $response->assertJsonPath('status', 'unknown');
        $response->assertJsonPath('match', null);
    }

    public function test_requer_autenticacao(): void
    {
        $file = new UploadedFile(
            base_path('tests/Fixtures/detect-magazord-custos-sample.csv'), 'custos.csv', 'text/csv', null, true
        );

        $response = $this->postJson(route('imports.hub.detect'), ['file' => $file]);

        // postJson() manda Accept: application/json — o middleware auth responde
        // 401 nesse caso (em vez do redirect pra /login usado em requests normais).
        $response->assertStatus(401);
    }
}
