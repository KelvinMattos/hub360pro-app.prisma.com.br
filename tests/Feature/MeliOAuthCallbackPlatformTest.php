<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SettingsController::handleMeliCallback() gravava a credencial por conta
 * com platform='mercadolivre', hardcoded, diferente da grafia usada pelo
 * resto do fluxo (config de chaves, redirectToMeli, refresh de token...).
 * Prova que o callback agora grava a mesma grafia canônica.
 */
class MeliOAuthCallbackPlatformTest extends TestCase
{
    use RefreshDatabase;

    public function test_oauth_callback_creates_credential_with_canonical_platform(): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 21600,
                'user_id' => 555444333,
            ]),
            'api.mercadolibre.com/users/me' => Http::response(['nickname' => 'SPORTIME.OFICIAL']),
        ]);

        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['company_id' => $companyId]);

        $configIntegration = Integration::create([
            'company_id' => $companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'app_id' => 'app-123',
            'client_secret' => 'secret-123',
            'status' => 'pending_auth',
        ]);

        session(['meli_code_verifier' => 'verifier-abc']);

        $response = $this->actingAs($user)->get(route('ml.callback', [
            'code' => 'auth-code-xyz',
            'state' => $configIntegration->id,
        ]));

        $response->assertRedirect(route('marketplaces.accounts.index'));

        $this->assertDatabaseHas('integrations', [
            'company_id' => $companyId,
            'platform' => 'mercadolibre',
            'seller_id' => '555444333',
            'external_user_id' => '555444333',
        ]);
        $this->assertDatabaseMissing('integrations', ['platform' => 'mercadolivre']);
    }
}
