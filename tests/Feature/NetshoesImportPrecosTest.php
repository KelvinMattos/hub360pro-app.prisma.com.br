<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Importação de Preços Netshoes (export "PRICE"): Sku Seller, Preço De,
 * Preço Por -> products.netshoes_price / netshoes_price_from. Mesmo padrão
 * de cruzamento por SKU do tipo "estoque" (não cria produto).
 */
class NetshoesImportPrecosTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_import_updates_matched_skus_and_reports_not_found(): void
    {
        $user = $this->authenticatedUser();
        DB::table('products')->insert([
            ['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $file = new UploadedFile(
            base_path('tests/Fixtures/netshoes-precos-sample.xlsx'), 'precos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );

        $response = $this->actingAs($user)->post(route('netshoes.import', ['type' => 'precos']), ['file' => $file]);

        $response->assertRedirect(route('netshoes.show', ['type' => 'precos']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-A', 'netshoes_price' => 402.90, 'netshoes_price_from' => 443.90,
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-B', 'netshoes_price' => 60.90, 'netshoes_price_from' => 66.90,
        ]);

        $result = session('importResult');
        $this->assertSame(2, $result['updated']);
        $this->assertSame(1, $result['skipped']); // SKU-NAOEXISTE
    }

    public function test_import_never_creates_new_products(): void
    {
        $user = $this->authenticatedUser();
        $before = DB::table('products')->count();

        $file = new UploadedFile(
            base_path('tests/Fixtures/netshoes-precos-sample.xlsx'), 'precos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'precos']), ['file' => $file]);

        $this->assertSame($before, DB::table('products')->count());
    }

    public function test_import_requires_authentication(): void
    {
        $file = new UploadedFile(
            base_path('tests/Fixtures/netshoes-precos-sample.xlsx'), 'precos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );

        $response = $this->post(route('netshoes.import', ['type' => 'precos']), ['file' => $file]);
        $response->assertRedirect(route('login'));
    }
}
