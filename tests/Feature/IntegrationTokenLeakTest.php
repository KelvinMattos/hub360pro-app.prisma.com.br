<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * MarketplaceListingController::index() serializa Integration direto como
 * prop do Inertia sem select nem Resource — access_token/refresh_token do
 * Mercado Livre iam parar no HTML/JSON entregue ao navegador.
 */
class IntegrationTokenLeakTest extends TestCase
{
    use RefreshDatabase;

    public function test_listings_page_never_exposes_access_or_refresh_token(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['company_id' => $companyId]);

        Integration::create([
            'company_id' => $companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'account_nickname' => 'Conta Secreta',
            'access_token' => 'SECRET-ACCESS-TOKEN-XYZ',
            'refresh_token' => 'SECRET-REFRESH-TOKEN-XYZ',
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('marketplaces.listings.index'));

        $response->assertOk();
        $response->assertDontSee('SECRET-ACCESS-TOKEN-XYZ');
        $response->assertDontSee('SECRET-REFRESH-TOKEN-XYZ');
    }

    public function test_integration_array_does_not_include_tokens(): void
    {
        $integration = new Integration([
            'access_token' => 'SECRET-ACCESS',
            'refresh_token' => 'SECRET-REFRESH',
            'client_secret' => 'SECRET-CLIENT',
            'account_nickname' => 'Conta',
        ]);

        $array = $integration->toArray();

        $this->assertArrayNotHasKey('access_token', $array);
        $this->assertArrayNotHasKey('refresh_token', $array);
        $this->assertArrayNotHasKey('client_secret', $array);
        $this->assertArrayHasKey('account_nickname', $array);
    }
}
