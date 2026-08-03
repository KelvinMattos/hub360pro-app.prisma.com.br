<?php

namespace Tests\Feature\Sales;

use App\Models\ChannelSalesDaily;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesChannelImportControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);
    }

    public function test_show_requires_authentication(): void
    {
        $this->get(route('sales.channel-import.show'))->assertRedirect(route('login'));
    }

    public function test_show_renderiza_tela_de_importacao(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-import.show'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->component('SalesChannel/Import'));
    }

    public function test_import_rejeita_extensao_invalida(): void
    {
        $file = UploadedFile::fake()->create('diario.txt', 10);

        $response = $this->actingAs($this->user)->post(route('sales.channel-import.import'), ['file' => $file]);

        $response->assertRedirect(route('sales.channel-import.show'));
        $response->assertSessionHas('error');
        $this->assertSame(0, ChannelSalesDaily::count());
    }

    public function test_import_grava_vendas_diarias_a_partir_do_arquivo_real(): void
    {
        $file = new UploadedFile(base_path('tests/Fixtures/sales-channel-diario-sample.xlsx'), 'diario.xlsx', null, null, true);

        $response = $this->actingAs($this->user)->post(route('sales.channel-import.import'), ['file' => $file]);

        $response->assertRedirect(route('sales.channel-import.show'));
        $response->assertSessionHas('success');
        $this->assertSame(5, ChannelSalesDaily::where('company_id', $this->companyId)->count());
    }

    public function test_progress_sem_token_conhecido_retorna_pending(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-import.progress', 'token-inexistente'));

        $response->assertOk();
        $response->assertJson(['status' => 'pending', 'done' => 0, 'total' => 0]);
    }

    public function test_import_com_progress_token_disponibiliza_progresso_via_polling(): void
    {
        $file = new UploadedFile(base_path('tests/Fixtures/sales-channel-diario-sample.xlsx'), 'diario.xlsx', null, null, true);
        $token = 'teste-token-abc';

        $this->actingAs($this->user)->post(route('sales.channel-import.import'), [
            'file' => $file, 'progress_token' => $token,
        ])->assertRedirect(route('sales.channel-import.show'));

        $progress = $this->actingAs($this->user)->get(route('sales.channel-import.progress', $token));
        $progress->assertOk();
        $progress->assertJson(['status' => 'done']);
        $progress->assertJsonStructure(['result' => ['ok', 'sheets_total', 'sheets_recognized', 'rows_imported']]);
    }
}
