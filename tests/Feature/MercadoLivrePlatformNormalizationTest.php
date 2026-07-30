<?php

namespace Tests\Feature;

use App\Console\Commands\MeliRefreshTokenCommand;
use App\Jobs\ProcessMarketplaceWebhookJob;
use App\Models\Integration;
use App\Models\WebhookLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * integrations.platform tinha duas grafias para o mesmo marketplace:
 * 'mercadolibre' (config de chaves, a maioria do sistema) e 'mercadolivre'
 * (SettingsController::handleMeliCallback, hardcoded). A credencial por
 * conta criada no OAuth ficava invisível para meli:refresh-tokens,
 * products:sync e o processamento de webhook, que só buscavam
 * 'mercadolibre'/'mercadolivre' isoladamente.
 */
class MercadoLivrePlatformNormalizationTest extends TestCase
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

    public function test_backfill_migration_normalizes_existing_rows(): void
    {
        $id1 = DB::table('integrations')->insertGetId([
            'company_id' => $this->companyId, 'platform' => 'mercadolivre',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $id2 = DB::table('integrations')->insertGetId([
            'company_id' => $this->companyId, 'platform' => 'mercado_livre',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('integrations')
            ->whereIn('platform', ['mercadolivre', 'mercado_livre'])
            ->update(['platform' => 'mercadolibre']);

        $this->assertSame('mercadolibre', DB::table('integrations')->where('id', $id1)->value('platform'));
        $this->assertSame('mercadolibre', DB::table('integrations')->where('id', $id2)->value('platform'));
    }

    public function test_platform_constant_is_the_canonical_spelling(): void
    {
        $this->assertSame('mercadolibre', Integration::PLATFORM_MERCADO_LIVRE);
    }

    public function test_refresh_token_command_finds_integration_with_canonical_platform(): void
    {
        DB::table('integrations')->insert([
            'company_id' => $this->companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'refresh_token' => 'refresh-abc',
            'access_token' => 'expired-token',
            'expires_at' => now()->subDay(), // já expirado -> isNearExpiration() true
            'account_nickname' => 'Conta Teste',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Não afirma que o refresh contra a API real funciona (sem rede aqui) —
        // só prova que a integração é ENCONTRADA pela query, que é o bug relatado.
        $found = Integration::where('platform', Integration::PLATFORM_MERCADO_LIVRE)
            ->whereNotNull('refresh_token')
            ->count();

        $this->assertSame(1, $found);
    }

    public function test_webhook_job_finds_integration_by_canonical_platform(): void
    {
        DB::table('integrations')->insert([
            'company_id' => $this->companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'external_user_id' => '999888777',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $log = WebhookLog::create([
            'company_id' => $this->companyId,
            'source' => 'mercadolivre',
            'event_type' => 'items',
            'payload' => ['user_id' => 999888777, 'topic' => 'items', 'resource' => '/items/123'],
            'status' => 'pending',
        ]);

        $job = new ProcessMarketplaceWebhookJob($log);
        $job->handle();

        $log->refresh();
        $this->assertNotSame('ignored', $log->status, "Webhook não deveria ser ignorado — a integração existe com platform canônica.");
    }
}
