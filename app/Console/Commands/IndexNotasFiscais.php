<?php

namespace App\Console\Commands;

use App\Services\NotasFiscais\NotaFiscalIndexService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class IndexNotasFiscais extends Command
{
    protected $signature = 'notas-fiscais:index {--company= : Indexa apenas esta empresa} {--force : Reindexa mesmo o que já está indexado e sem mudança}';

    protected $description = 'Varre a pasta de notas fiscais de compra e indexa o texto dos PDFs novos/alterados para busca';

    public function handle(NotaFiscalIndexService $service): int
    {
        $force = (bool) $this->option('force');
        $companyOption = $this->option('company');

        $companies = $companyOption
            ? collect([(int) $companyOption])
            : DB::table('companies')->pluck('id');

        if ($companies->isEmpty()) {
            $this->warn('Nenhuma empresa encontrada.');

            return self::SUCCESS;
        }

        foreach ($companies as $companyId) {
            $bar = $this->output->createProgressBar();
            $bar->start();

            $result = $service->indexAll((int) $companyId, $force, function (int $done, int $total) use ($bar) {
                $bar->setMaxSteps(max($total, 1));
                $bar->setProgress($done);
            });

            $bar->finish();
            $this->newLine();

            if (! $result['ok']) {
                $this->error("Empresa {$companyId}: {$result['error']}");

                continue;
            }

            $this->info("Empresa {$companyId} — indexados: {$result['indexed']} | ignorados: {$result['skipped']} | falhas: {$result['failed']} (de {$result['total']} PDFs).");
        }

        return self::SUCCESS;
    }
}
