<?php

namespace App\Console\Commands;

use App\Models\Integration;
use App\Services\GoogleAds\GoogleAdsApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sincroniza gasto de campanha do Google Ads (via API real) direto pra
 * `ad_spend_daily` — mesma tabela/chave única do importador manual
 * (AdsImportController::importSpend), então o Dashboard de ADS não muda
 * nada: passa a ver dado vindo de dois lugares (upload manual OU API) na
 * mesma tela, sem distinção.
 *
 * Cron-only por design (CLAUDE.md — "worker não é confiável no cPanel"):
 * roda via `Schedule::command('google-ads:sync-spend')->hourly()`
 * (routes/console.php), que depende só do cron padrão do Laravel
 * (`* * * * * php artisan schedule:run`) já configurado no cPanel pros
 * outros comandos agendados.
 *
 * Nunca falha em silêncio (CLAUDE.md §2.1/§2.2): se a API falhar pra uma
 * conta, essa conta não grava NADA de gasto (não fabrica valor parcial) e o
 * erro fica em `integrations.last_sync_error`, visível na tela de Conexões.
 */
class GoogleAdsSyncSpendCommand extends Command
{
    protected $signature = 'google-ads:sync-spend {--company=} {--days=8}';

    protected $description = 'Busca o gasto de campanha das contas de Google Ads conectadas via API e grava em ad_spend_daily.';

    public function handle(GoogleAdsApiService $service): int
    {
        $days = max(1, (int) $this->option('days'));

        $query = Integration::where('platform', Integration::PLATFORM_GOOGLE_ADS)
            ->where('is_active', true)
            ->whereNotNull('seller_id') // só contas de verdade, não a linha de config
            ->whereNotNull('refresh_token');

        if ($companyId = $this->option('company')) {
            $query->where('company_id', (int) $companyId);
        }

        $integrations = $query->get();
        if ($integrations->isEmpty()) {
            $this->info('Nenhuma conta de Google Ads conectada pra sincronizar.');
            return self::SUCCESS;
        }

        $since = now()->subDays($days)->toDateString();
        $until = now()->toDateString();
        $ok = 0;
        $failed = 0;

        foreach ($integrations as $integration) {
            $customerId = $integration->seller_id;
            $this->info("Sincronizando Google Ads {$customerId} (empresa {$integration->company_id})...");

            try {
                $rows = $service->fetchCampaignSpend($integration, $customerId, $since, $until);

                $adAccount = DB::table('ad_accounts')->where([
                    'company_id' => $integration->company_id,
                    'platform' => 'google_ads',
                    'external_account_id' => $customerId,
                ])->first();

                if (!$adAccount) {
                    $adAccountId = DB::table('ad_accounts')->insertGetId([
                        'company_id' => $integration->company_id,
                        'platform' => 'google_ads',
                        'label' => $integration->account_nickname ?: "Google Ads {$customerId}",
                        'external_account_id' => $customerId,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $adAccountId = $adAccount->id;
                }

                foreach ($rows as $r) {
                    DB::table('ad_spend_daily')->updateOrInsert(
                        ['ad_account_id' => $adAccountId, 'date' => $r['date'], 'campaign_name' => mb_substr($r['campaign_name'], 0, 190)],
                        [
                            'company_id' => $integration->company_id,
                            'platform' => 'google_ads',
                            'campaign_id' => $r['campaign_id'],
                            'spend' => $r['spend'],
                            'impressions' => $r['impressions'],
                            'clicks' => $r['clicks'],
                            'conversions' => $r['conversions'],
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                }

                $integration->forceFill([
                    'last_sync_at' => now(),
                    'last_sync_status' => 'ok',
                    'last_sync_error' => null,
                ])->save();

                $this->info("  OK — " . count($rows) . ' linhas.');
                $ok++;
            } catch (\Throwable $e) {
                Log::error("google-ads:sync-spend falhou pra integration {$integration->id} ({$customerId}): " . $e->getMessage());
                $integration->forceFill([
                    'last_sync_at' => now(),
                    'last_sync_status' => 'error',
                    'last_sync_error' => mb_substr($e->getMessage(), 0, 1000),
                ])->save();

                $this->error("  Falha: {$e->getMessage()}");
                $failed++;
            }
        }

        $this->info("Concluído: {$ok} conta(s) sincronizada(s), {$failed} com falha.");
        return $failed > 0 && $ok === 0 ? self::FAILURE : self::SUCCESS;
    }
}
