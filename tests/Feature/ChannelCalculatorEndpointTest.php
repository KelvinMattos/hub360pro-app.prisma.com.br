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
}
