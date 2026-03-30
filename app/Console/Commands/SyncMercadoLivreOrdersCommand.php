<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Jobs\SyncMercadoLivreOrdersJob;
use App\Models\Company;

class SyncMercadoLivreOrdersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'hub:sync-ml-orders {company_id} {--days=30}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispara o Job de sincronização massiva de pedidos do Mercado Livre';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $companyId = $this->argument('company_id');
        $days = $this->option('days');

        $company = Company::findOrFail($companyId);

        $dateFrom = now()->subDays($days)->toIso8601String();
        $dateTo = now()->toIso8601String();

        $this->info("Disparando job de sincronização para a empresa: {$company->name} (ID: {$companyId})");
        $this->info("Período: Últimos {$days} dias.");

        SyncMercadoLivreOrdersJob::dispatch($companyId, $dateFrom, $dateTo);

        $this->info("Job enviado para a fila com sucesso! Monitore via Laravel Horizon.");
    }
}
