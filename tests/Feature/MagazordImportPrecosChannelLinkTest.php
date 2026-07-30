<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A "Consulta Dinâmica – Custo x Preço de Venda" traz o preço praticado em
 * cada canal (Site, Mercado Livre, Shopee, Netshoes, ...) numa coluna por
 * canal; "0,00" significa que o produto não é vendido naquele canal. Cliente
 * pediu para vincular o preço ao canal sempre que a coluna vier preenchida
 * (> 0) — igual já acontece com a importação dedicada da Netshoes. Validado
 * contra um export real de 11944 linhas via tinker antes destes testes.
 */
class MagazordImportPrecosChannelLinkTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function precosFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/magazord-precos-sample.csv'), 'precos.csv', 'text/csv', null, true
        );
    }

    public function test_channel_price_is_linked_only_when_column_is_filled(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $response = $this->actingAs($user)->post(route('magazord.import', ['type' => 'precos']), ['file' => $this->precosFile()]);

        $response->assertRedirect(route('magazord.show', ['type' => 'precos']));
        $response->assertSessionHas('success');

        $a = Product::where('sku', 'SKU-A')->first();
        $cpA = json_decode($a->channel_prices, true);
        $this->assertSame(9.9, $cpA['Site']);
        $this->assertSame(12.9, $cpA['Netshoes']);
        $this->assertArrayNotHasKey('Amazon', $cpA); // veio "0,00" -> não vincula

        $b = Product::where('sku', 'SKU-B')->first();
        $cpB = json_decode($b->channel_prices, true);
        $this->assertArrayNotHasKey('Netshoes', $cpB); // veio "0,00" -> não vincula
    }

    public function test_netshoes_channel_price_syncs_to_dedicated_netshoes_price_field(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'precos']), ['file' => $this->precosFile()]);

        // SKU-A: coluna Netshoes = 12,90 -> deve sincronizar com o mesmo campo
        // usado pela importação dedicada (Importações Netshoes -> Preços).
        $a = Product::where('sku', 'SKU-A')->first();
        $this->assertSame('12.90', $a->netshoes_price);
        $this->assertNotNull($a->netshoes_synced_at);

        // SKU-B: coluna Netshoes = 0,00 (não vende no canal) -> não deve tocar.
        $b = Product::where('sku', 'SKU-B')->first();
        $this->assertNull($b->netshoes_price);
    }

    public function test_import_reports_not_found_sku_and_channel_summary_in_message(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'precos']), ['file' => $this->precosFile()]);

        $this->assertStringContainsString('1 SKUs não encontrados', session('success'));
        $this->assertStringContainsString('Preço por canal vinculado', session('success'));
        $this->assertStringContainsString('Netshoes:', session('success'));
    }

    public function test_import_requires_authentication(): void
    {
        $response = $this->post(route('magazord.import', ['type' => 'precos']), ['file' => $this->precosFile()]);
        $response->assertRedirect(route('login'));
    }
}
