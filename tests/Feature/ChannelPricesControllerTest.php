<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tela dedicada "Preços por Canal" — cliente pediu uma visão de "o preço de
 * cada produto em cada canal que é vendido", lado a lado.
 */
class ChannelPricesControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_index_shows_channel_prices_side_by_side(): void
    {
        $user = $this->authenticatedUser();

        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'Produto A',
            'status' => 'active', 'stock_quantity' => 10, 'sale_price' => 100,
            'channel_prices' => json_encode(['Mercado Livre' => 120, 'Shopee' => 0]),
            'netshoes_price' => 110,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('pricing.channel-prices'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Pricing/ChannelPrices')
            ->where('rows.0.sku', 'SKU-A')
            ->where('rows.0.prices.Site', 100)
            ->where('rows.0.prices.Mercado Livre', 120)
            ->where('rows.0.prices.Netshoes', 110)
            ->where('rows.0.prices.Shopee', null)
        );
    }

    public function test_only_channel_filter_hides_products_without_that_channel(): void
    {
        $user = $this->authenticatedUser();

        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'COM-ML', 'title' => 'Vendido no ML',
            'status' => 'active', 'stock_quantity' => 5, 'sale_price' => 50,
            'channel_prices' => json_encode(['Mercado Livre' => 60]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'SEM-ML', 'title' => 'Não vendido no ML',
            'status' => 'active', 'stock_quantity' => 5, 'sale_price' => 50,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('pricing.channel-prices', ['only_channel' => 'Mercado Livre']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('stats.total', 1)
            ->where('rows.0.sku', 'COM-ML')
        );
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('pricing.channel-prices'));
        $response->assertRedirect(route('login'));
    }
}
