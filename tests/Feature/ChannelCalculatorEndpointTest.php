<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PricingEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A Calculadora de Canais calculava tudo em JS no navegador — agora delega
 * para App\Services\PricingEngine via este endpoint. Testa ponta a ponta
 * (rota + controller + engine), autenticado como a tela realmente é usada.
 */
class ChannelCalculatorEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_compute_endpoint_matches_pricing_engine_directly(): void
    {
        $user = $this->authenticatedUser();

        $payload = [
            'custo' => 100.0,
            'preco' => 250.0,
            'imposto' => 8.0,
            'mc' => 11.0,
            'markup' => 23.433,
            'channels' => [
                ['id' => 'site', 'label' => 'Site', 'comissao' => 2.0, 'temFaixa' => 'none', 'descAtual' => 20, 'descEquil' => 10],
                ['id' => 'ml_classico', 'label' => 'ML Clássico', 'comissao' => 18.0, 'temFaixa' => 'ml', 'descAtual' => 20, 'descEquil' => 10],
            ],
        ];

        $response = $this->actingAs($user)->postJson(route('calculator.compute'), $payload);

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(2, $rows);

        $engine = app(PricingEngine::class);

        $site = collect($rows)->firstWhere('id', 'site');
        $encargosSite = 8.0 + 11.0 + 2.0;
        $this->assertSame(round($engine->tieredBreakEven(100.0, $encargosSite, 'none'), 2), $site['equilibrio']);
        $this->assertSame(round($engine->unitContribution(250.0, 100.0, $encargosSite), 2), $site['margem']);

        $ml = collect($rows)->firstWhere('id', 'ml_classico');
        $encargosMl = 8.0 + 11.0 + 18.0;
        $this->assertSame(round($engine->tieredBreakEven(100.0, $encargosMl, 'ml'), 2), $ml['equilibrio']);
    }

    public function test_compute_endpoint_requires_authentication(): void
    {
        $response = $this->postJson(route('calculator.compute'), ['custo' => 100, 'imposto' => 8, 'mc' => 11, 'markup' => 20, 'channels' => []]);
        $response->assertUnauthorized();
    }

    public function test_compute_endpoint_validates_required_fields(): void
    {
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->postJson(route('calculator.compute'), []);
        $response->assertStatus(422);
    }

    public function test_compute_endpoint_without_price_omits_margin_but_keeps_break_even(): void
    {
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->postJson(route('calculator.compute'), [
            'custo' => 80.0, 'preco' => null, 'imposto' => 8, 'mc' => 11, 'markup' => 20,
            'channels' => [['id' => 'site', 'comissao' => 2, 'temFaixa' => 'none']],
        ]);

        $response->assertOk();
        $row = $response->json('rows.0');
        $this->assertNull($row['margem']);
        $this->assertNotNull($row['equilibrio']);
    }

    public function test_round_disabled_keeps_exact_equilibrio_and_meta(): void
    {
        $user = $this->authenticatedUser();
        $engine = app(PricingEngine::class);
        $encargos = 8.0 + 11.0 + 2.0;

        $response = $this->actingAs($user)->postJson(route('calculator.compute'), [
            'custo' => 100.0, 'preco' => null, 'imposto' => 8, 'mc' => 11, 'markup' => 23.433,
            'roundEnabled' => false,
            'channels' => [['id' => 'site', 'comissao' => 2, 'temFaixa' => 'none']],
        ]);

        $row = $response->json('rows.0');
        $rawEquilibrio = $engine->tieredBreakEven(100.0, $encargos, 'none');
        $this->assertSame($rawEquilibrio, $row['equilibrio']);
    }

    public function test_round_enabled_rounds_equilibrio_and_meta_to_selected_ending(): void
    {
        $user = $this->authenticatedUser();
        $engine = app(PricingEngine::class);
        $encargos = 8.0 + 11.0 + 2.0;

        $response = $this->actingAs($user)->postJson(route('calculator.compute'), [
            'custo' => 100.0, 'preco' => null, 'imposto' => 8, 'mc' => 11, 'markup' => 23.433,
            'roundEnabled' => true, 'roundEnding' => '50',
            'channels' => [['id' => 'site', 'comissao' => 2, 'temFaixa' => 'none']],
        ]);

        $row = $response->json('rows.0');
        $rawEquilibrio = $engine->tieredBreakEven(100.0, $encargos, 'none');
        $rawMeta = round($rawEquilibrio * (1 + 23.433 / 100), 2);

        $this->assertSame($engine->roundToCharm($rawEquilibrio, 0.50), $row['equilibrio']);
        $this->assertSame($engine->roundToCharm($rawMeta, 0.50), $row['meta']);
        // Prova que o arredondamento realmente mudou o valor exibido (não é coincidência).
        $this->assertNotSame($rawEquilibrio, $row['equilibrio']);
    }

    /**
     * A terminação escolhida na tela nunca pode mudar se um preço é classificado
     * como "Lucro"/"Prejuízo"/"Abaixo eq." — status e margem sempre usam o valor
     * exato, só a exibição de Equilíbrio/Meta/Promo muda com o arredondamento.
     */
    public function test_round_enabled_does_not_change_status_or_margin(): void
    {
        $user = $this->authenticatedUser();
        $engine = app(PricingEngine::class);
        $encargos = 8.0 + 11.0 + 2.0;
        $equilibrio = $engine->tieredBreakEven(100.0, $encargos, 'none');

        $payloadBase = [
            'custo' => 100.0, 'preco' => $equilibrio, 'imposto' => 8, 'mc' => 11, 'markup' => 23.433,
            'channels' => [['id' => 'site', 'comissao' => 2, 'temFaixa' => 'none']],
        ];

        $rounded = $this->actingAs($user)->postJson(route('calculator.compute'),
            $payloadBase + ['roundEnabled' => true, 'roundEnding' => '99']);
        $raw = $this->actingAs($user)->postJson(route('calculator.compute'),
            $payloadBase + ['roundEnabled' => false]);

        $this->assertSame($raw->json('rows.0.status'), $rounded->json('rows.0.status'));
        $this->assertSame($raw->json('rows.0.margem'), $rounded->json('rows.0.margem'));
    }

    public function test_promo_price_uses_selected_rounding_ending(): void
    {
        $user = $this->authenticatedUser();
        $engine = app(PricingEngine::class);
        $encargos = 8.0 + 11.0 + 2.0;
        $equilibrio = $engine->tieredBreakEven(100.0, $encargos, 'none');

        $response = $this->actingAs($user)->postJson(route('calculator.compute'), [
            'custo' => 100.0, 'preco' => 250.0, 'imposto' => 8, 'mc' => 11, 'markup' => 23.433,
            'roundEnabled' => true, 'roundEnding' => '00',
            'channels' => [['id' => 'site', 'comissao' => 2, 'temFaixa' => 'none', 'descAtual' => 20, 'descEquil' => 10]],
        ]);

        $expectedPromo = round($engine->suggestedPromoPrice(250.0, $equilibrio, 20, 10, 0.00), 2);
        $this->assertSame($expectedPromo, (float) $response->json('rows.0.promo'));
    }

    public function test_round_ending_rejects_unlisted_value(): void
    {
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->postJson(route('calculator.compute'), [
            'custo' => 100.0, 'imposto' => 8, 'mc' => 11, 'markup' => 20,
            'roundEnabled' => true, 'roundEnding' => '77',
            'channels' => [['id' => 'site', 'comissao' => 2]],
        ]);
        $response->assertStatus(422);
    }
}
