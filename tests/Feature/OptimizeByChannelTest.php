<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\MarketOptimizerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cliente pediu: "Otimizar" organizado por canal, sempre preservando a
 * margem saudável de cada canal (comissão diferente por canal). Reaproveita
 * o mesmo critério de vínculo do Cálculo Promo (channel_prices/netshoes_price)
 * e o mesmo piso (custo + encargos do canal + margem mínima) do RepricingEngine.
 */
class OptimizeByChannelTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_only_products_linked_to_the_channel_appear_and_floor_uses_channel_commission(): void
    {
        $user = $this->authenticatedUser();

        // Vendido no ML (comissão alta, 18% + imposto 8% = 26% de encargos por padrão).
        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'COM-ML', 'title' => 'Vendido no ML',
            'status' => 'active', 'cost_price' => 100, 'sale_price' => 150,
            'channel_prices' => json_encode(['Mercado Livre' => 130]), // abaixo do piso de propósito
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'SEM-ML', 'title' => 'Não vendido no ML',
            'status' => 'active', 'cost_price' => 100, 'sale_price' => 150,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(MarketOptimizerService::class)->opportunitiesByChannel($user->company_id, 10);
        $ml = collect($result['channels'])->firstWhere('key', 'ml_classico');

        $this->assertNotNull($ml);
        $skus = collect($ml['items'])->pluck('sku')->all();
        $this->assertContains('COM-ML', $skus);
        $this->assertNotContains('SEM-ML', $skus);

        $item = collect($ml['items'])->firstWhere('sku', 'COM-ML');
        // Encargos do canal ML Clássico: comissão 18% + imposto 8% = 26%.
        $this->assertSame(26.0, $item['encargos_pct']);
        // Piso = 100 / (1 - 0.36) = 156.25 (26% encargos + 10% margem mínima) > 130 -> não saudável.
        $this->assertFalse($item['saudavel']);
        $this->assertEqualsWithDelta(156.25, $item['piso'], 0.01);
    }

    public function test_healthy_margin_product_is_flagged_as_saudavel(): void
    {
        $user = $this->authenticatedUser();

        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'SAUDAVEL', 'title' => 'Margem boa',
            'status' => 'active', 'cost_price' => 50, 'sale_price' => 150,
            'channel_prices' => json_encode(['Mercado Livre' => 200]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(MarketOptimizerService::class)->opportunitiesByChannel($user->company_id, 10);
        $ml = collect($result['channels'])->firstWhere('key', 'ml_classico');
        $item = collect($ml['items'])->firstWhere('sku', 'SAUDAVEL');

        $this->assertTrue($item['saudavel']);
    }

    public function test_netshoes_suggestion_never_goes_below_the_channel_floor(): void
    {
        $user = $this->authenticatedUser();

        // Perdendo Buy Box, mas o mercado está tão baixo que o preço undercut
        // ficaria abaixo do piso — a sugestão deve travar no piso, nunca abaixo.
        DB::table('products')->insert([
            'company_id' => $user->company_id, 'sku' => 'NETSHOES-RISCO', 'title' => 'Netshoes',
            'status' => 'active', 'cost_price' => 100, 'netshoes_price' => 200,
            'market_price' => 105, // undercut ficaria ~104,90 - abaixo do piso
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(MarketOptimizerService::class)->opportunitiesByChannel($user->company_id, 10);
        $netshoes = collect($result['channels'])->firstWhere('key', 'netshoes');
        $item = collect($netshoes['items'])->firstWhere('sku', 'NETSHOES-RISCO');

        $this->assertTrue($item['perdendo_buybox']);
        $this->assertNotNull($item['sugerido']);
        $this->assertGreaterThanOrEqual($item['piso'], $item['sugerido']);
    }

    public function test_apply_writes_to_netshoes_price_for_netshoes_channel(): void
    {
        $user = $this->authenticatedUser();
        $productId = DB::table('products')->insertGetId([
            'company_id' => $user->company_id, 'sku' => 'X', 'title' => 'X',
            'status' => 'active', 'cost_price' => 50, 'netshoes_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('monitoring.optimize.apply', ['product' => $productId]), [
            'price' => 89.90, 'channel' => 'netshoes',
        ]);

        $response->assertSessionHas('success');
        $product = DB::table('products')->find($productId);
        $this->assertEquals(89.90, (float) $product->netshoes_price);
    }

    public function test_apply_writes_to_channel_prices_json_for_other_channels(): void
    {
        $user = $this->authenticatedUser();
        $productId = DB::table('products')->insertGetId([
            'company_id' => $user->company_id, 'sku' => 'Y', 'title' => 'Y',
            'status' => 'active', 'cost_price' => 50, 'sale_price' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->post(route('monitoring.optimize.apply', ['product' => $productId]), [
            'price' => 129.90, 'channel' => 'shopee',
        ]);

        $response->assertSessionHas('success');
        $product = DB::table('products')->find($productId);
        $cp = json_decode($product->channel_prices, true);
        $this->assertEquals(129.90, (float) $cp['Shopee']);
    }

    public function test_index_page_loads_with_channels_organized(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->get(route('monitoring.optimize'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Monitoring/Optimize')
            ->has('porCanal')
        );
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('monitoring.optimize'));
        $response->assertRedirect(route('login'));
    }
}
