<?php

namespace Tests\Feature;

use App\Jobs\ImportMarketPricesJob;
use App\Services\Monitoring\MarketPriceImportProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportMarketPricesJobTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_handle_processes_csv_and_updates_market_price(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'ABC', 'title' => 'Produto ABC',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $path = 'private/market-imports/test.csv';
        Storage::put($path, "SKU;Preço Mercado;Vendedor\nABC;199,90;Loja X\n");

        $job = new ImportMarketPricesJob($this->companyId, $path, false, 'test-token');
        $job->handle(app(MarketPriceImportProcessor::class));

        $this->assertDatabaseHas('products', [
            'id' => $productId, 'market_seller' => 'Loja X', 'market_source' => 'import',
        ]);
        $price = DB::table('products')->where('id', $productId)->value('market_price');
        $this->assertSame(199.90, (float) $price);

        Storage::assertMissing($path);
    }

    public function test_handle_does_not_throw_when_file_is_missing(): void
    {
        $job = new ImportMarketPricesJob($this->companyId, 'private/market-imports/missing.csv', false, 'tok');
        $job->handle(app(MarketPriceImportProcessor::class));
        $this->assertTrue(true); // não lançou exceção
    }

    public function test_handle_writes_across_multiple_batches(): void
    {
        // BATCH_SIZE do processor é 500 — usa 550 linhas para provar que o
        // flush final (depois do último lote parcial) também é gravado.
        $rows = ["SKU;Preço Mercado;Vendedor"];
        $productIds = [];
        for ($i = 0; $i < 550; $i++) {
            $sku = "SKU{$i}";
            $productIds[] = DB::table('products')->insertGetId([
                'company_id' => $this->companyId, 'sku' => $sku, 'title' => "Produto {$i}",
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $rows[] = "{$sku};10,00;Loja";
        }

        $path = 'private/market-imports/big.csv';
        Storage::put($path, implode("\n", $rows) . "\n");

        $job = new ImportMarketPricesJob($this->companyId, $path, false, 'tok-big');
        $job->handle(app(MarketPriceImportProcessor::class));

        $updatedCount = DB::table('products')
            ->where('company_id', $this->companyId)
            ->where('market_price', 10.00)
            ->count();

        $this->assertSame(550, $updatedCount);
    }

    public function test_handle_matches_by_netshoes_sku_fallback(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'INTERNAL-1', 'netshoes_sku' => 'NSH-1',
            'title' => 'Produto', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $path = 'private/market-imports/nsh.csv';
        Storage::put($path, "SKU;Preço Mercado;Vendedor\nNSH-1;50,00;Concorrente\n");

        $job = new ImportMarketPricesJob($this->companyId, $path, false, 'tok-nsh');
        $job->handle(app(MarketPriceImportProcessor::class));

        $price = DB::table('products')->where('id', $productId)->value('market_price');
        $this->assertSame(50.0, (float) $price);
    }
}
