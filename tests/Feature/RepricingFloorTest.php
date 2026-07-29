<?php

namespace Tests\Feature;

use App\Services\RepricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Prova a correção do bug central desta refatoração: o piso do Repricing
 * ignorava os encargos do canal (`custo × (1 + margem)`), permitindo preço
 * "piso" abaixo do ponto de equilíbrio real — ou seja, autorizando repricing
 * no prejuízo. Agora o piso vem de PricingEngine::floorPrice(), que soma os
 * encargos do canal (comissão + imposto) ao custo antes da margem.
 */
class RepricingFloorTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'company_id' => $this->companyId,
            'sku' => 'SKU-' . uniqid(),
            'title' => 'Produto Teste',
            'brand' => 'MarcaX',
            'price' => 200.00,
            'cost_price' => 100.00,
            'market_price' => 150.00,
            'market_source' => 'manual',
            'market_checked_at' => now(),
            // products não tem selling_channel no schema real — o Buy Box hoje
            // é 100% Netshoes, então o fallback do RepricingEngine é sempre
            // usado na prática (ver resolveCharges()).
            'monitored' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_floor_includes_channel_charges_not_just_margin(): void
    {
        // Netshoes tem 24% de comissão no defaultConfig() + 8% de imposto = 32% de encargos.
        // custo=100, margem mínima=10% => piso = 100 / (1 - 0.32 - 0.10) = 172.41
        $productId = $this->makeProduct(['cost_price' => 100.00, 'market_price' => 150.00]);

        $engine = app(RepricingEngine::class);
        $plan = $engine->plan($this->companyId, ['min_margin' => 10, 'only_losing' => false]);

        $item = collect($plan['items'])->firstWhere('id', $productId);
        $this->assertNotNull($item);

        // A fórmula antiga daria 100 * 1.10 = 110.00 — bem abaixo do piso correto.
        $this->assertGreaterThan(110.0, $item['piso']);
        $this->assertEqualsWithDelta(172.41, $item['piso'], 0.5);
    }

    public function test_new_price_below_correct_floor_is_blocked(): void
    {
        // market_price bem próximo do custo => o preço sugerido (undercut do
        // mercado) fica abaixo do piso real, e a trava 1 tem que barrar.
        $productId = $this->makeProduct([
            'cost_price' => 100.00,
            'market_price' => 105.00, // sugerido ~104.90, abaixo do piso ~172
            'price' => 200.00,
        ]);

        $engine = app(RepricingEngine::class);
        $plan = $engine->plan($this->companyId, ['min_margin' => 10, 'only_losing' => false]);

        $item = collect($plan['items'])->firstWhere('id', $productId);
        $this->assertFalse($item['aplicavel']);
        $this->assertStringContainsString('Abaixo do piso', $item['bloqueio']);
        $this->assertStringContainsString('encargos do canal', $item['bloqueio']);
    }

    public function test_new_price_above_correct_floor_is_applicable(): void
    {
        $productId = $this->makeProduct([
            'cost_price' => 50.00,
            'market_price' => 300.00, // sugerido ~299.90, folgado acima do piso
            'price' => 350.00,
        ]);

        $engine = app(RepricingEngine::class);
        $plan = $engine->plan($this->companyId, [
            'min_margin' => 10, 'only_losing' => false, 'max_change_pct' => 100,
        ]);

        $item = collect($plan['items'])->firstWhere('id', $productId);
        $this->assertTrue($item['aplicavel'], $item['bloqueio'] ?? 'sem motivo');
    }

    public function test_brand_margin_overrides_global_and_still_respects_channel_charges(): void
    {
        $productId = $this->makeProduct(['brand' => 'MarcaX', 'cost_price' => 100, 'market_price' => 150]);
        $engine = app(RepricingEngine::class);

        $planSemOverride = $engine->plan($this->companyId, ['min_margin' => 5, 'only_losing' => false]);
        $pisoSemOverride = collect($planSemOverride['items'])->firstWhere('id', $productId)['piso'];

        $engine->saveBrandMargin($this->companyId, 'MarcaX', 25.0); // margem alta específica da marca
        $planComOverride = $engine->plan($this->companyId, ['min_margin' => 5, 'only_losing' => false]);
        $item = collect($planComOverride['items'])->firstWhere('id', $productId);

        $this->assertSame(25.0, $item['margem']);
        // Piso com a margem da marca (25%) tem que ser MAIOR que com a margem global (5%).
        $this->assertGreaterThan($pisoSemOverride, $item['piso']);
    }
}
