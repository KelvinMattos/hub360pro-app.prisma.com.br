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
}
