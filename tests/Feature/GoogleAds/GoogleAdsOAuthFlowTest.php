<?php

namespace Tests\Feature\GoogleAds;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integração real via API do Google Ads (pedido do cliente 04/08/2026,
 * "aprenda e integre" — nada de upload de planilha). Mesmo fluxo OAuth2 já
 * usado com o Mercado Livre (SettingsController::redirectToMeli/
 * handleMeliCallback), adaptado pro contrato do Google (developer token +
 * client id/secret + refresh token via https://oauth2.googleapis.com/token,
 * chamadas REST em https://googleads.googleapis.com).
 *
 * O sandbox de desenvolvimento não alcança developers.google.com/
 * googleads.googleapis.com (bloqueio de rede do ambiente — mesma política
 * que já bloqueia netshoes.com.br), então aqui a API é simulada via
 * Http::fake() com o formato de request/response documentado publicamente.
 * Validação de verdade fim-a-fim só acontece em produção.
 */
class GoogleAdsOAuthFlowTest extends TestCase
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

    public function test_update_google_ads_keys_cria_linha_de_configuracao(): void
    {
        $response = $this->actingAs($this->user)->post(route('settings.google-ads.keys'), [
            'developer_token' => 'DEV_TOKEN_ABC',
            'app_id' => 'CLIENT_ID_123',
            'client_secret' => 'CLIENT_SECRET_XYZ',
            'login_customer_id' => '111-222-3333',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('integrations', [
            'company_id' => $this->companyId, 'platform' => 'google_ads',
            'app_id' => 'CLIENT_ID_123', 'developer_token' => 'DEV_TOKEN_ABC',
            'login_customer_id' => '111-222-3333', 'status' => 'pending_auth', 'seller_id' => null,
        ]);
    }

    public function test_redirect_to_google_ads_exige_chaves_salvas(): void
    {
        $response = $this->actingAs($this->user)->get(route('google-ads.connect'));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('error');
    }

    public function test_redirect_to_google_ads_monta_url_de_autorizacao_correta(): void
    {
        Integration::create([
            'company_id' => $this->companyId, 'platform' => 'google_ads',
            'developer_token' => 'DEV_TOKEN_ABC', 'app_id' => 'CLIENT_ID_123', 'client_secret' => 'SECRET',
        ]);

        $response = $this->actingAs($this->user)->get(route('google-ads.connect'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);
        $this->assertStringContainsString('client_id=CLIENT_ID_123', $location);
        $this->assertStringContainsString('scope=' . urlencode('https://www.googleapis.com/auth/adwords'), $location);
        $this->assertStringContainsString('access_type=offline', $location);
        $this->assertStringContainsString('prompt=consent', $location);
    }

    public function test_callback_troca_code_e_conecta_contas_acessiveis(): void
    {
        $config = Integration::create([
            'company_id' => $this->companyId, 'platform' => 'google_ads',
            'developer_token' => 'DEV_TOKEN_ABC', 'app_id' => 'CLIENT_ID_123', 'client_secret' => 'SECRET',
        ]);

        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ACCESS_1', 'refresh_token' => 'REFRESH_1', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'customers:listAccessibleCustomers')) {
                return Http::response(['resourceNames' => ['customers/1112223333', 'customers/4445556666']], 200);
            }
            if (str_contains($url, 'googleAds:search')) {
                $body = $request->data();
                $customerId = str_contains($url, '1112223333') ? '1112223333' : '4445556666';
                $name = $customerId === '1112223333' ? 'Loja Principal' : 'Loja Secundária';

                return Http::response(['results' => [
                    ['customer' => ['id' => $customerId, 'descriptiveName' => $name]],
                ]], 200);
            }

            return Http::response([], 404);
        });

        $response = $this->actingAs($this->user)->get(route('google-ads.callback', ['code' => 'auth-code-xyz', 'state' => $config->id]));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('integrations', [
            'company_id' => $this->companyId, 'platform' => 'google_ads', 'seller_id' => '1112223333',
            'account_nickname' => 'Loja Principal', 'status' => 'active', 'is_active' => true, 'developer_token' => 'DEV_TOKEN_ABC',
        ]);
        $this->assertDatabaseHas('integrations', [
            'company_id' => $this->companyId, 'platform' => 'google_ads', 'seller_id' => '4445556666',
            'account_nickname' => 'Loja Secundária',
        ]);
        $this->assertSame(3, DB::table('integrations')->where('platform', 'google_ads')->count()); // config + 2 contas
    }

    public function test_callback_sem_code_cancela_com_erro(): void
    {
        $response = $this->actingAs($this->user)->get(route('google-ads.callback', ['error' => 'access_denied']));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('integrations')->count());
    }

    public function test_callback_com_falha_na_troca_de_token_nao_cria_conta(): void
    {
        $config = Integration::create([
            'company_id' => $this->companyId, 'platform' => 'google_ads',
            'developer_token' => 'DEV_TOKEN_ABC', 'app_id' => 'CLIENT_ID_123', 'client_secret' => 'SECRET',
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant', 'error_description' => 'código expirado'], 400),
        ]);

        $response = $this->actingAs($this->user)->get(route('google-ads.callback', ['code' => 'expired-code', 'state' => $config->id]));

        $response->assertRedirect(route('settings.integrations'));
        $response->assertSessionHas('error');
        $this->assertSame(1, DB::table('integrations')->count()); // só a linha de config, nenhuma conta criada
    }
}
