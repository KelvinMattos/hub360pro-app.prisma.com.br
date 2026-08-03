<?php

namespace Tests\Feature\NotasFiscais;

use App\Models\NotaFiscalCompra;
use App\Models\NotaFiscalPagina;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class NotaFiscalControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('notas-fiscais.index'))->assertRedirect(route('login'));
    }

    public function test_index_lista_notas_da_empresa(): void
    {
        $user = $this->authenticatedUser();
        NotaFiscalCompra::create([
            'company_id' => $user->company_id, 'path' => 'a.pdf', 'filename' => 'a.pdf',
            'status' => 'indexed', 'pages_count' => 1,
        ]);
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        NotaFiscalCompra::create([
            'company_id' => $outraEmpresa, 'path' => 'b.pdf', 'filename' => 'b.pdf',
            'status' => 'indexed', 'pages_count' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('notas-fiscais.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('NotasFiscais/Index')
            ->has('notas.data', 1)
            ->where('notas.data.0.filename', 'a.pdf')
        );
    }

    public function test_index_com_termo_retorna_resultados_da_busca(): void
    {
        $user = $this->authenticatedUser();
        $nota = NotaFiscalCompra::create([
            'company_id' => $user->company_id, 'path' => 'a.pdf', 'filename' => 'a.pdf',
            'status' => 'indexed', 'pages_count' => 1,
        ]);
        NotaFiscalPagina::create([
            'nota_fiscal_compra_id' => $nota->id, 'page_number' => 1,
            'content' => 'PRODUTO TENIS EAN 1112223334445',
        ]);
        DB::commit(); // FULLTEXT do InnoDB só enxerga após commit (ver NotaFiscalIndexServiceTest).

        $response = $this->actingAs($user)->get(route('notas-fiscais.index', ['termo' => '1112223334445']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('NotasFiscais/Index')
            ->has('resultados', 1)
            ->where('resultados.0.filename', 'a.pdf')
            ->where('resultados.0.page_number', 1)
        );
    }

    public function test_view_serve_pdf_da_propria_empresa(): void
    {
        $user = $this->authenticatedUser();
        Storage::fake('notas_fiscais');
        Storage::disk('notas_fiscais')->put('a.pdf', file_get_contents(base_path('tests/Fixtures/notas-fiscais/nota-teste.pdf')));
        $nota = NotaFiscalCompra::create([
            'company_id' => $user->company_id, 'path' => 'a.pdf', 'filename' => 'a.pdf',
            'status' => 'indexed', 'pages_count' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('notas-fiscais.view', $nota));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_view_bloqueia_pdf_de_outra_empresa(): void
    {
        $user = $this->authenticatedUser();
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $nota = NotaFiscalCompra::create([
            'company_id' => $outraEmpresa, 'path' => 'a.pdf', 'filename' => 'a.pdf',
            'status' => 'indexed', 'pages_count' => 1,
        ]);

        $this->actingAs($user)->get(route('notas-fiscais.view', $nota))->assertNotFound();
    }

    public function test_reindex_dispara_indexacao_e_reporta_resultado(): void
    {
        $user = $this->authenticatedUser();
        Storage::fake('notas_fiscais');
        Storage::disk('notas_fiscais')->put('a.pdf', file_get_contents(base_path('tests/Fixtures/notas-fiscais/nota-teste.pdf')));

        $response = $this->actingAs($user)->post(route('notas-fiscais.reindex'));

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('notas_fiscais_compra', [
            'company_id' => $user->company_id, 'filename' => 'a.pdf', 'status' => 'indexed',
        ]);
    }

    /**
     * Incidente relatado pelo cliente (03/08/2026): com milhares de PDFs reais,
     * a reindexação sempre caía em erro 500 "depois de alguns instantes" —
     * faltava `set_time_limit(0)`, então o max_execution_time padrão do PHP
     * matava o processo no meio do laço. Sem forma de reproduzir milhares de
     * PDFs num teste, o que dá pra travar é o essencial: o progresso fica
     * disponível via polling (Cache::store('file')) desde o início até o
     * status 'done', igual ao padrão já usado nas importações Magazord/Netshoes.
     */
    public function test_reindex_com_progress_token_disponibiliza_progresso_via_polling(): void
    {
        $user = $this->authenticatedUser();
        Storage::fake('notas_fiscais');
        Storage::disk('notas_fiscais')->put('a.pdf', file_get_contents(base_path('tests/Fixtures/notas-fiscais/nota-teste.pdf')));

        $token = 'teste-token-123';

        $response = $this->actingAs($user)->post(route('notas-fiscais.reindex'), ['progress_token' => $token]);
        $response->assertRedirect();

        $progress = $this->actingAs($user)->get(route('notas-fiscais.reindex.progress', $token));
        $progress->assertOk();
        $progress->assertJson(['status' => 'done', 'done' => 1, 'total' => 1]);
        $progress->assertJsonStructure(['result' => ['ok', 'indexed', 'failed', 'skipped', 'total']]);
    }

    /** Sem token nenhum ainda salvo, o polling responde 'pending' em vez de erro. */
    public function test_reindex_progress_sem_token_conhecido_retorna_pending(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->get(route('notas-fiscais.reindex.progress', 'token-inexistente'));

        $response->assertOk();
        $response->assertJson(['status' => 'pending', 'done' => 0, 'total' => 0]);
    }
}
