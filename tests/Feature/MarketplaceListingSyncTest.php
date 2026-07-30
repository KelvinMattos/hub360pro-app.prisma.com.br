<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MarketplaceListingService::syncListings() engolia qualquer exceção (catch
 * Exception + Log::error) e nunca devolvia nada — o controller sempre
 * respondia sucesso. Testado com a credencial 1: o log gravava "Mercado
 * Livre authentication failed" e a tela mostrava sucesso mesmo assim.
 * Também cobre o botão "Sincronizar Tudo" com credential_id nulo.
 */
class MarketplaceListingSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);
    }

    private function makeCredential(array $overrides = []): Integration
    {
        return Integration::create(array_merge([
            'company_id' => $this->companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'seller_id' => '123',
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-token',
            // Sem expires_at -> isNearExpiration() true -> tenta refreshToken() antes de qualquer request.
            'is_active' => true,
            'account_nickname' => 'Conta Teste',
        ], $overrides));
    }

    public function test_sync_reports_real_error_when_token_refresh_fails(): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $credential = $this->makeCredential();

        $response = $this->actingAs($this->user)
            ->post(route('marketplaces.listings.sync'), ['credential_id' => $credential->id]);

        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertStringContainsString('Mercado Livre authentication failed', session('error'));
    }

    public function test_sync_reports_real_success_with_counts(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::response(['scroll_id' => null, 'results' => ['MLB1']]),
            'api.mercadolibre.com/items*' => Http::response([
                ['body' => [
                    'id' => 'MLB1', 'title' => 'Produto', 'price' => 100, 'available_quantity' => 5,
                    'permalink' => 'https://x', 'thumbnail' => null, 'condition' => 'new',
                    'status' => 'active', 'listing_type_id' => 'gold', 'category_id' => 'MLB1',
                    'attributes' => [],
                ]],
            ]),
        ]);

        $credential = $this->makeCredential(['expires_at' => now()->addDay(), 'token_expires_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)
            ->post(route('marketplaces.listings.sync'), ['credential_id' => $credential->id]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('1 criado', session('success'));
        $this->assertDatabaseHas('products', ['company_id' => $this->companyId, 'external_id' => 'MLB1']);
    }

    public function test_sync_all_when_no_credential_selected(): void
    {
        Http::fake([
            'api.mercadolibre.com/users/*/items/search*' => Http::response(['scroll_id' => null, 'results' => []]),
        ]);

        $this->makeCredential(['expires_at' => now()->addDay(), 'token_expires_at' => now()->addDay(), 'seller_id' => '1']);
        $this->makeCredential(['expires_at' => now()->addDay(), 'token_expires_at' => now()->addDay(), 'seller_id' => '2']);

        $response = $this->actingAs($this->user)
            ->post(route('marketplaces.listings.sync'), ['credential_id' => null]);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('2 conta', session('success'));
    }

    public function test_sync_all_reports_error_when_no_active_integration_exists(): void
    {
        $response = $this->actingAs($this->user)
            ->post(route('marketplaces.listings.sync'), ['credential_id' => null]);

        $response->assertSessionHas('error');
    }
}
