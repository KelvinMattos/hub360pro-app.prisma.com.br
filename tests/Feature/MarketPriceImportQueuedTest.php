<?php

namespace Tests\Feature;

use App\Jobs\ImportMarketPricesJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Um arquivo de 41999 linhas rodando síncrono no request era cortado pelo
 * timeout de borda do Cloudflare (~100s) e, como tudo ficava numa única
 * transação, nada era gravado — market_price nunca chegou a ser preenchido
 * numa importação grande (reproduzido em produção, 0 de 79610 produtos).
 * Este teste prova que o controller agora só ENFILEIRA o job e volta na
 * hora — o processamento pesado não roda mais dentro do request.
 */
class MarketPriceImportQueuedTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_import_dispatches_job_instead_of_processing_synchronously(): void
    {
        Queue::fake();
        Storage::fake('local');
        $user = $this->authenticatedUser();

        $file = UploadedFile::fake()->createWithContent('precos.csv', "SKU;Preço Mercado;Vendedor\nABC;199,90;Loja X\n");

        $response = $this->actingAs($user)->post(route('monitoring.market.import'), ['file' => $file]);

        $response->assertRedirect(route('monitoring.market.form'));
        Queue::assertPushed(ImportMarketPricesJob::class);
    }

    public function test_import_rejects_unsupported_extension(): void
    {
        Queue::fake();
        $user = $this->authenticatedUser();
        $file = UploadedFile::fake()->create('produtos.pdf', 10);

        $response = $this->actingAs($user)->post(route('monitoring.market.import'), ['file' => $file]);

        $response->assertRedirect(route('monitoring.market.form'));
        Queue::assertNotPushed(ImportMarketPricesJob::class);
    }

    public function test_import_requires_authentication(): void
    {
        $file = UploadedFile::fake()->create('precos.csv', 10);
        $response = $this->post(route('monitoring.market.import'), ['file' => $file]);
        $response->assertRedirect(route('login'));
    }
}
