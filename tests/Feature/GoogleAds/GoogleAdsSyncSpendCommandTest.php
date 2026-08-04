<?php

namespace Tests\Feature\GoogleAds;

use App\Models\Integration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `php artisan google-ads:sync-spend` — busca gasto de campanha via API real
 * e grava em ad_spend_daily, a MESMA tabela/chave única usada pelo
 * importador manual (AdsImportController), então o Dashboard de ADS não
 * precisa saber se o dado veio de upload ou de API.
 */
class GoogleAdsSyncSpendCommandTest extends TestCase
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

    private function connectedAccount(array $overrides = []): Integration
    {
        return Integration::create(array_merge([
            'company_id' => $this->companyId,
            'platform' => 'google_ads',
            'seller_id' => '1112223333',
            'external_user_id' => '1112223333',
            'account_nickname' => 'Loja Principal',
            'developer_token' => 'DEV_TOKEN_ABC',
            'app_id' => 'CLIENT_ID_123',
            'client_secret' => 'SECRET',
            'access_token' => 'ACCESS_VALID',
            'refresh_token' => 'REFRESH_1',
            'token_expires_at' => now()->addHours(3), // bem longe do limiar de 60min de isNearExpiration() — não deve disparar refresh
            'status' => 'active',
            'is_active' => true,
        ], $overrides));
    }

    public function test_sincroniza_gasto_de_campanha_com_paginacao(): void
    {
        $account = $this->connectedAccount();

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'googleAds:search')) {
                $body = $request->data();
                if (empty($body['pageToken'])) {
                    return Http::response([
                        'results' => [
                            ['campaign' => ['id' => '111', 'name' => 'Campanha A'], 'segments' => ['date' => '2026-08-01'], 'metrics' => ['costMicros' => '150500000', 'impressions' => '10000', 'clicks' => '320', 'conversions' => '12']],
                        ],
                        'nextPageToken' => 'page2',
                    ], 200);
                }

                return Http::response([
                    'results' => [
                        ['campaign' => ['id' => '112', 'name' => 'Campanha B'], 'segments' => ['date' => '2026-08-02'], 'metrics' => ['costMicros' => '80250000', 'impressions' => '5000', 'clicks' => '150', 'conversions' => '4']],
                    ],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('google-ads:sync-spend', ['--company' => $this->companyId])->assertExitCode(0);

        $this->assertDatabaseHas('ad_spend_daily', [
            'company_id' => $this->companyId, 'platform' => 'google_ads', 'campaign_name' => 'Campanha A',
            'date' => '2026-08-01', 'spend' => 150.5, 'impressions' => 10000, 'clicks' => 320, 'conversions' => 12,
        ]);
        $this->assertDatabaseHas('ad_spend_daily', [
            'campaign_name' => 'Campanha B', 'date' => '2026-08-02', 'spend' => 80.25,
        ]);

        $adAccount = DB::table('ad_accounts')->where('external_account_id', '1112223333')->first();
        $this->assertNotNull($adAccount);
        $this->assertSame('google_ads', $adAccount->platform);

        $account->refresh();
        $this->assertSame('ok', $account->last_sync_status);
        $this->assertNull($account->last_sync_error);
        $this->assertNotNull($account->last_sync_at);
    }

    public function test_renova_access_token_expirado_antes_de_consultar(): void
    {
        $account = $this->connectedAccount(['access_token' => 'OLD_TOKEN', 'token_expires_at' => now()->subMinutes(5)]);

        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'NEW_TOKEN', 'expires_in' => 3600], 200);
            }
            if (str_contains($url, 'googleAds:search')) {
                // só aceita se o token renovado foi usado
                $auth = $request->header('Authorization')[0] ?? '';
                if ($auth !== 'Bearer NEW_TOKEN') {
                    return Http::response(['error' => ['message' => 'token inválido']], 401);
                }

                return Http::response(['results' => [
                    ['campaign' => ['id' => '111', 'name' => 'Campanha A'], 'segments' => ['date' => '2026-08-01'], 'metrics' => ['costMicros' => '10000000']],
                ]], 200);
            }

            return Http::response([], 404);
        });

        $this->artisan('google-ads:sync-spend', ['--company' => $this->companyId])->assertExitCode(0);

        $account->refresh();
        $this->assertSame('NEW_TOKEN', $account->access_token);
        $this->assertSame('ok', $account->last_sync_status);
        $this->assertDatabaseHas('ad_spend_daily', ['campaign_name' => 'Campanha A', 'spend' => 10.0]);
    }

    public function test_falha_na_api_nao_grava_nada_e_registra_o_erro(): void
    {
        $account = $this->connectedAccount();

        Http::fake([
            'googleads.googleapis.com/*' => Http::response(['error' => ['message' => 'Developer token não autorizado para essa conta.']], 403),
        ]);

        $this->artisan('google-ads:sync-spend', ['--company' => $this->companyId]);

        $this->assertSame(0, DB::table('ad_spend_daily')->count());

        $account->refresh();
        $this->assertSame('error', $account->last_sync_status);
        $this->assertStringContainsString('Developer token não autorizado', $account->last_sync_error);
    }

    public function test_ignora_contas_inativas(): void
    {
        $this->connectedAccount(['is_active' => false]);

        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => []], 200)]);

        $this->artisan('google-ads:sync-spend', ['--company' => $this->companyId])->expectsOutputToContain('Nenhuma conta');
    }

    public function test_escopa_por_empresa_quando_option_company_informada(): void
    {
        $this->connectedAccount();
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $this->connectedAccount(['company_id' => $outraEmpresa, 'seller_id' => '9998887777']);

        Http::fake(['googleads.googleapis.com/*' => Http::response(['results' => []], 200)]);

        $this->artisan('google-ads:sync-spend', ['--company' => $this->companyId]);

        Http::assertSentCount(1); // só a conta da empresa filtrada
    }
}
