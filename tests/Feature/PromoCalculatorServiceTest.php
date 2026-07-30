<?php

namespace Tests\Feature;

use App\Http\Controllers\Pricing\CalculoPromoController;
use App\Services\PricingEngine;
use App\Services\PromoCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regressão pós-refatoração: Cálculo Promo delega break-even/promo sugerido/
 * margem para PricingEngine. Este teste recalcula os mesmos valores
 * diretamente no engine e confere que compute() bate exatamente.
 */
class PromoCalculatorServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_matches_pricing_engine_formulas_exactly(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'company_id' => $companyId,
            'sku' => 'PROMO-1',
            'title' => 'Tenis Teste',
            'cost_price' => 80.00,
            'sale_price' => 250.00,
            'stock_quantity' => 5,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cfg = CalculoPromoController::defaultConfig();
        $service = app(PromoCalculatorService::class);
        $result = $service->compute($companyId, $cfg, 'site');

        $row = collect($result['rows'])->firstWhere('sku', 'PROMO-1');
        $this->assertNotNull($row);

        $engine = app(PricingEngine::class);
        $channel = collect($cfg['channels'])->firstWhere('id', 'site');
        $encargos = $cfg['imposto'] + $cfg['mc'] + $channel['comissao'];

        $expectedBreakEven = $engine->tieredBreakEven(80.00, $encargos, $channel['temFaixa']);
        $expectedMeta = round($expectedBreakEven * (1 + $channel['markup'] / 100), 2);
        $expectedPromo = $engine->suggestedPromoPrice(250.00, $expectedBreakEven, $channel['descAtual'], $channel['descEquil'], $cfg['rounding']);
        $expectedResultAtual = $engine->unitContribution(250.00, 80.00, $encargos);

        $this->assertSame(round($expectedBreakEven, 2), $row['ponto_equilibrio']);
        $this->assertSame($expectedMeta, $row['meta_lucro']);
        $this->assertSame(round($expectedPromo, 2), $row['promo_sugerido']);
        $this->assertSame(round($expectedResultAtual, 2), $row['resultado_atual']);
    }

    public function test_compute_handles_product_without_cost_gracefully(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa2', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'SEM-CUSTO', 'title' => 'Sem custo',
            'cost_price' => 0, 'sale_price' => 100, 'stock_quantity' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cfg = CalculoPromoController::defaultConfig();
        $result = app(PromoCalculatorService::class)->compute($companyId, $cfg, 'site');

        $row = collect($result['rows'])->firstWhere('sku', 'SEM-CUSTO');
        $this->assertNull($row['ponto_equilibrio']);
        $this->assertNull($row['promo_sugerido']);
    }

    /**
     * Cliente pediu: ao mudar de canal, só aparecem SKUs com vínculo real
     * àquele canal (channel_prices preenchido) — sem vínculo, não aparece.
     * Antes, qualquer canal sem preço específico caía pro sale_price base,
     * fazendo TODO produto aparecer em TODO canal.
     */
    public function test_only_products_linked_to_the_channel_appear_for_marketplace_channels(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa3', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'COM-VINCULO', 'title' => 'Vendido no ML',
            'cost_price' => 50, 'sale_price' => 150, 'stock_quantity' => 3,
            'channel_prices' => json_encode(['Mercado Livre' => 180.00]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'SEM-VINCULO', 'title' => 'Só vendido no site',
            'cost_price' => 50, 'sale_price' => 150, 'stock_quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cfg = CalculoPromoController::defaultConfig();
        $result = app(PromoCalculatorService::class)->compute($companyId, $cfg, 'ml_classico');

        $skus = collect($result['rows'])->pluck('sku')->all();
        $this->assertContains('COM-VINCULO', $skus);
        $this->assertNotContains('SEM-VINCULO', $skus);

        $row = collect($result['rows'])->firstWhere('sku', 'COM-VINCULO');
        $this->assertSame(180.0, $row['pv_atual']);
    }

    public function test_site_channel_still_falls_back_to_base_sale_price_without_regression(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa4', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'SO-SALE-PRICE', 'title' => 'Sem channel_prices',
            'cost_price' => 50, 'sale_price' => 150, 'stock_quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cfg = CalculoPromoController::defaultConfig();
        $result = app(PromoCalculatorService::class)->compute($companyId, $cfg, 'site');

        $row = collect($result['rows'])->firstWhere('sku', 'SO-SALE-PRICE');
        $this->assertNotNull($row);
        $this->assertSame(150.0, $row['pv_atual']);
    }

    public function test_netshoes_channel_accepts_dedicated_netshoes_price_field(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa5', 'created_at' => now(), 'updated_at' => now()]);

        // Produto sincronizado só pelo importador dedicado (Importações Netshoes
        // -> Preços), sem passar pela planilha Magazord (sem channel_prices).
        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'NETSHOES-DEDICADO', 'title' => 'Netshoes via importador dedicado',
            'cost_price' => 50, 'sale_price' => 150, 'stock_quantity' => 3,
            'netshoes_price' => 199.90,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $companyId, 'sku' => 'SEM-NETSHOES', 'title' => 'Não vendido na Netshoes',
            'cost_price' => 50, 'sale_price' => 150, 'stock_quantity' => 3,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cfg = CalculoPromoController::defaultConfig();
        $result = app(PromoCalculatorService::class)->compute($companyId, $cfg, 'netshoes');

        $skus = collect($result['rows'])->pluck('sku')->all();
        $this->assertContains('NETSHOES-DEDICADO', $skus);
        $this->assertNotContains('SEM-NETSHOES', $skus);

        $row = collect($result['rows'])->firstWhere('sku', 'NETSHOES-DEDICADO');
        $this->assertSame(199.9, $row['pv_atual']);
    }
}
