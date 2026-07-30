<?php

namespace Tests\Unit;

use App\Models\Integration;
use App\Services\Adapters\MercadoLivreAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MercadoLivreAdapter::fetchProducts() fazia UMA única chamada a
 * /users/{seller_id}/items/search (limit=100, sem offset/scroll) — uma
 * conta com 33 mil SKUs (caso real: SPORTIME.OFICIAL, seller 474079443)
 * nunca traria mais que os 100 primeiros. Corrigido para paginar via scroll
 * (search_type=scan + scroll_id), que não tem o teto de offset+limit<=1000
 * do modo tradicional.
 */
class MercadoLivreAdapterPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function makeCredential(): Integration
    {
        $companyId = \Illuminate\Support\Facades\DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return Integration::create([
            'company_id' => $companyId,
            'platform' => 'mercadolivre',
            'seller_id' => '474079443',
            'access_token' => 'fake-token',
            'refresh_token' => 'fake-refresh',
            'expires_at' => now()->addDay(),
            'token_expires_at' => now()->addDay(),
            'is_active' => true,
        ]);
    }

    public function test_fetch_products_paginates_across_multiple_scroll_pages(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::sequence()
                ->push(['scroll_id' => 'SCROLL-1', 'results' => ['ITEM1', 'ITEM2']])
                ->push(['scroll_id' => 'SCROLL-2', 'results' => ['ITEM3']])
                ->push(['scroll_id' => 'SCROLL-3', 'results' => []]),
            'api.mercadolibre.com/items*' => Http::response($this->itemsBatchResponse(['ITEM1', 'ITEM2', 'ITEM3'])),
        ]);

        $adapter = new MercadoLivreAdapter();
        $products = $adapter->fetchProducts($this->makeCredential());

        $this->assertCount(3, $products);
        $this->assertSame(['ITEM1', 'ITEM2', 'ITEM3'], array_column($products, 'external_id'));

        Http::assertSentCount(4); // 3 páginas de busca + 1 lookup de detalhes (chunk único de 3 ids)
    }

    public function test_fetch_products_sends_scroll_id_on_subsequent_requests(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::sequence()
                ->push(['scroll_id' => 'SCROLL-ABC', 'results' => ['ITEM1']])
                ->push(['scroll_id' => 'SCROLL-XYZ', 'results' => []]),
            'api.mercadolibre.com/items*' => Http::response($this->itemsBatchResponse(['ITEM1'])),
        ]);

        $adapter = new MercadoLivreAdapter();
        $adapter->fetchProducts($this->makeCredential());

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/items/search')
                && str_contains($request->url(), 'scroll_id=SCROLL-ABC');
        });
    }

    public function test_fetch_products_stops_on_empty_results_without_scroll_id(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::response(['scroll_id' => null, 'results' => []]),
        ]);

        $adapter = new MercadoLivreAdapter();
        $products = $adapter->fetchProducts($this->makeCredential());

        $this->assertSame([], $products);
        Http::assertSentCount(1); // não insiste em paginar quando já não há nada
    }

    public function test_fetch_products_stops_on_failed_search_request(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::response(['message' => 'error'], 500),
        ]);

        $adapter = new MercadoLivreAdapter();
        $products = $adapter->fetchProducts($this->makeCredential());

        $this->assertSame([], $products);
    }

    private function itemsBatchResponse(array $ids): array
    {
        return array_map(fn ($id) => [
            'body' => [
                'id' => $id,
                'title' => "Produto {$id}",
                'price' => 100,
                'available_quantity' => 5,
                'permalink' => "https://produto.mercadolivre.com.br/{$id}",
                'thumbnail' => null,
                'condition' => 'new',
                'status' => 'active',
                'listing_type_id' => 'gold_special',
                'category_id' => 'MLB1234',
                'attributes' => [],
            ],
        ], $ids);
    }
}
