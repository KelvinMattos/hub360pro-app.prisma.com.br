<?php

namespace App\Console\Commands;

use App\Services\Netshoes\BuyBoxSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coleta de Buy Box da Netshoes por linha de comando (para agendar no cron do
 * cPanel). Ex.:
 *   php artisan netshoes:buybox --limit=300
 *   php artisan netshoes:buybox --company=1 --force
 */
class ScrapeNetshoesBuyBox extends Command
{
    protected $signature = 'netshoes:buybox
        {--company= : ID da empresa (padrão: todas)}
        {--limit= : Máximo de produtos nesta rodada}
        {--force : Recoletar mesmo os verificados recentemente}';

    protected $description = 'Coleta preço e vendedor da Buy Box na Netshoes pelo SKU Netshoes';

    public function handle(BuyBoxSyncService $sync): int
    {
        if (!Schema::hasColumn('products', 'netshoes_sku')) {
            $this->error('Coluna products.netshoes_sku não existe. Rode as migrations.');
            return self::FAILURE;
        }

        $companies = $this->option('company')
            ? [(int) $this->option('company')]
            : $this->allCompanies();

        if (empty($companies)) {
            $this->warn('Nenhuma empresa encontrada.');
            return self::SUCCESS;
        }

        $grand = ['total' => 0, 'ok' => 0, 'fail' => 0, 'winning' => 0, 'losing' => 0];

        foreach ($companies as $companyId) {
            $opts = ['force' => (bool) $this->option('force')];
            if ($this->option('limit')) {
                $opts['batch_limit'] = (int) $this->option('limit');
            }

            $this->info("Empresa {$companyId}: iniciando coleta…");
            $bar = null;

            $stats = $sync->run($companyId, $opts, function ($s) use (&$bar) {
                if ($bar === null && $s['total'] > 0) {
                    $bar = $this->output->createProgressBar($s['total']);
                    $bar->start();
                }
                if ($bar) {
                    $bar->setProgress(min($s['total'], $s['ok'] + $s['fail']));
                }
            });

            if ($bar) {
                $bar->finish();
                $this->newLine();
            }

            foreach ($grand as $k => $_) {
                $grand[$k] += $stats[$k] ?? 0;
            }

            $this->line("  → {$stats['ok']} ok, {$stats['fail']} falhas, "
                . "Buy Box: {$stats['winning']} ganhando / {$stats['losing']} perdendo");
        }

        $this->newLine();
        $this->info("Total: {$grand['ok']} coletados, {$grand['fail']} falhas, "
            . "{$grand['winning']} ganhando / {$grand['losing']} perdendo a Buy Box.");

        return self::SUCCESS;
    }

    private function allCompanies(): array
    {
        try {
            if (Schema::hasColumn('products', 'company_id')) {
                return DB::table('products')->whereNotNull('company_id')
                    ->distinct()->pluck('company_id')->map(fn ($v) => (int) $v)->all();
            }
        } catch (\Throwable $e) {
        }
        return [];
    }
}
