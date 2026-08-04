<?php

namespace Tests\Feature\Ads;

use App\Models\AdAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Importador de gasto de campanha (Google Ads/Meta Ads) -> ad_spend_daily.
 * Parser DEFENSIVO (procura a linha de cabeçalho em vez de supor a posição,
 * pois os relatórios reais costumam trazer 1-2 linhas de título e uma linha
 * de "Total" no fim) — ainda não validado contra um export real do cliente
 * (CLAUDE.md §2.4), então estes testes cobrem o formato PADRÃO documentado
 * de cada plataforma, não um arquivo real.
 */
class AdsImportControllerTest extends TestCase
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

    private function account(string $platform): AdAccount
    {
        return AdAccount::create([
            'company_id' => $this->companyId, 'platform' => $platform, 'label' => 'Conta Teste', 'is_active' => true,
        ]);
    }

    private function googleAdsCsv(): UploadedFile
    {
        $content = "Relatório de campanhas\r\n"
            . "Período: 1 de ago de 2026 - 4 de ago de 2026\r\n"
            . "\r\n"
            . "Campaign,Day,Cost,Impr.,Clicks,Conversions\r\n"
            . "Campanha A,2026-08-01,150.50,10000,320,12\r\n"
            . "Campanha A,2026-08-02,120.00,9000,280,9\r\n"
            . "Campanha B,2026-08-01,80.25,5000,150,4\r\n"
            . "Total,,350.75,24000,750,25\r\n";

        return UploadedFile::fake()->createWithContent('campanhas.csv', $content);
    }

    private function metaAdsCsv(): UploadedFile
    {
        $content = "Nome da campanha,Dia,Valor usado (BRL),Impressões,Cliques no link,Resultados\r\n"
            . "Campanha Meta A,2026-08-01,200.50,8000,210,15\r\n"
            . "Campanha Meta B,2026-08-01,99.99,4000,80,3\r\n";

        return UploadedFile::fake()->createWithContent('gastos.csv', $content);
    }

    private function googleAdsXlsx(): UploadedFile
    {
        $sheet = new Spreadsheet();
        $ws = $sheet->getActiveSheet();
        $ws->setCellValue('A1', 'Relatório de campanhas');
        $ws->setCellValue('A3', 'Campaign');
        $ws->setCellValue('B3', 'Day');
        $ws->setCellValue('C3', 'Cost');
        $ws->setCellValue('A4', 'Campanha XLSX');
        $ws->setCellValue('B4', '2026-08-01');
        $ws->setCellValue('C4', 42.5);

        $path = tempnam(sys_get_temp_dir(), 'gads') . '.xlsx';
        (new Xlsx($sheet))->save($path);

        return new UploadedFile($path, 'campanhas.xlsx', null, null, true);
    }

    public function test_show_requires_authentication(): void
    {
        $this->get(route('ads.import.show', ['type' => 'google_ads']))->assertRedirect(route('login'));
    }

    public function test_show_404_para_tipo_desconhecido(): void
    {
        $this->actingAs($this->user)->get(route('ads.import.show', ['type' => 'tiktok_ads']))->assertNotFound();
    }

    public function test_import_exige_conta_valida(): void
    {
        $response = $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsCsv(), 'account_id' => 99999,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('ad_spend_daily')->count());
    }

    public function test_import_rejeita_conta_de_outra_plataforma(): void
    {
        $metaAccount = $this->account('meta_ads');

        $response = $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsCsv(), 'account_id' => $metaAccount->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('ad_spend_daily')->count());
    }

    public function test_import_google_ads_encontra_cabecalho_e_ignora_linha_de_total(): void
    {
        $account = $this->account('google_ads');

        $response = $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsCsv(), 'account_id' => $account->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(3, DB::table('ad_spend_daily')->where('platform', 'google_ads')->count());
        $this->assertDatabaseHas('ad_spend_daily', [
            'ad_account_id' => $account->id, 'campaign_name' => 'Campanha A', 'date' => '2026-08-01',
            'spend' => 150.5, 'impressions' => 10000, 'clicks' => 320, 'conversions' => 12,
        ]);
        $this->assertDatabaseHas('ad_spend_daily', [
            'ad_account_id' => $account->id, 'campaign_name' => 'Campanha B', 'date' => '2026-08-01', 'spend' => 80.25,
        ]);
        // linha "Total" (sem Day válido) não gravou registro nenhum
        $this->assertDatabaseMissing('ad_spend_daily', ['campaign_name' => 'Total']);
    }

    public function test_import_meta_ads_captura_valor_usado_e_resultados(): void
    {
        $account = $this->account('meta_ads');

        $response = $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'meta_ads']), [
            'file' => $this->metaAdsCsv(), 'account_id' => $account->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ad_spend_daily', [
            'ad_account_id' => $account->id, 'platform' => 'meta_ads', 'campaign_name' => 'Campanha Meta A',
            'date' => '2026-08-01', 'spend' => 200.5, 'conversions' => 15,
        ]);
    }

    public function test_import_xlsx_encontra_cabecalho_fora_da_primeira_linha(): void
    {
        $account = $this->account('google_ads');

        $response = $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsXlsx(), 'account_id' => $account->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ad_spend_daily', [
            'ad_account_id' => $account->id, 'campaign_name' => 'Campanha XLSX', 'date' => '2026-08-01', 'spend' => 42.5,
        ]);
    }

    public function test_import_e_idempotente_ao_reimportar_mesma_campanha_dia(): void
    {
        $account = $this->account('google_ads');

        $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsCsv(), 'account_id' => $account->id,
        ]);
        $countAfterFirst = DB::table('ad_spend_daily')->count();

        $this->actingAs($this->user)->post(route('ads.import.import', ['type' => 'google_ads']), [
            'file' => $this->googleAdsCsv(), 'account_id' => $account->id,
        ]);
        $countAfterSecond = DB::table('ad_spend_daily')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_progress_sem_token_conhecido_retorna_pending(): void
    {
        $response = $this->actingAs($this->user)->get(route('ads.import.progress', 'token-inexistente'));

        $response->assertOk();
        $response->assertJson(['status' => 'pending', 'done' => 0, 'total' => 0]);
    }
}
