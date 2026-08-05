<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Painel de Vendas da Amazon (Seller Central → Business Reports → Sales
 * Dashboard, .csv) — pedido do cliente 05/08/2026, depois que o primeiro
 * arquivo enviado pro canal Amazon (`vendasamazon.txt`) se revelou, na
 * prática, um relatório de Listagens/Catálogo (SKU/preço/estoque/status do
 * anúncio), não de vendas. Fixture reproduz a estrutura real validada:
 * várias seções de metadado antes da série diária de fato, que começa no
 * cabeçalho "Horário" e termina antes de "Comparar vendas - Exibição de
 * tabela".
 */
class AmazonSalesDashboardImportTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function file(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/amazon-sales-dashboard-sample.csv'), 'vendasamazon.csv', 'text/csv', null, true
        );
    }

    public function test_reconhece_o_csv_da_amazon_pelo_conteudo_e_grava_serie_diaria(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->post(route('sales.channel-import.import'), ['file' => $this->file()]);

        $response->assertRedirect(route('sales.channel-import.show'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('channel_sales_daily', [
            'company_id' => $user->company_id, 'channel' => 'amazon', 'sale_date' => '2026-03-01',
            'gross_value' => 500, 'paid_value' => 500, 'orders_count' => 0,
        ]);
        $this->assertDatabaseHas('channel_sales_daily', [
            'company_id' => $user->company_id, 'channel' => 'amazon', 'sale_date' => '2026-03-03',
            'gross_value' => 500.50, 'paid_value' => 500.50,
        ]);
        // Dia com "R$ 0,00" ainda é um dia real do período — não pode ser descartado.
        $this->assertDatabaseHas('channel_sales_daily', [
            'company_id' => $user->company_id, 'channel' => 'amazon', 'sale_date' => '2026-03-02',
            'gross_value' => 0,
        ]);
        $this->assertSame(3, DB::table('channel_sales_daily')->where('channel', 'amazon')->count());
    }

    public function test_reimportar_atualiza_em_vez_de_duplicar(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('sales.channel-import.import'), ['file' => $this->file()]);
        $this->actingAs($user)->post(route('sales.channel-import.import'), ['file' => $this->file()]);

        $this->assertSame(3, DB::table('channel_sales_daily')->where('channel', 'amazon')->count());
    }

    public function test_csv_nao_reconhecido_e_rejeitado_sem_gravar_nada(): void
    {
        $user = $this->authenticatedUser();

        $path = tempnam(sys_get_temp_dir(), 'notamazon');
        file_put_contents($path, "Coluna A,Coluna B\n1,2\n");
        $file = new UploadedFile($path, 'aleatorio.csv', 'text/csv', null, true);

        $response = $this->actingAs($user)->post(route('sales.channel-import.import'), ['file' => $file]);

        $response->assertRedirect(route('sales.channel-import.show'));
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('channel_sales_daily')->count());
    }

    public function test_requires_authentication(): void
    {
        $response = $this->post(route('sales.channel-import.import'), ['file' => $this->file()]);
        $response->assertRedirect(route('login'));
    }
}
