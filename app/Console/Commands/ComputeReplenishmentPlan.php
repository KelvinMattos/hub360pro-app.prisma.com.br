<?php

namespace App\Console\Commands;

use App\Services\Inventory\ReplenishmentEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Job diário da Reposição Inteligente. Roda por empresa e faz upsert em
 * `replenishment_plan` — a tela (/inventory/planning) nunca calcula isso na
 * hora do request.
 */
class ComputeReplenishmentPlan extends Command
{
    protected $signature = 'inventory:compute-replenishment {--company= : Recalcula apenas esta empresa}';

    protected $description = 'Recalcula velocidade, cobertura, status e quantidade sugerida de reposição por SKU';

    public function handle(ReplenishmentEngine $engine): int
    {
        $companyOption = $this->option('company');

        $companies = $companyOption
            ? collect([(int) $companyOption])
            : DB::table('companies')->pluck('id');

        if ($companies->isEmpty()) {
            $this->warn('Nenhuma empresa encontrada.');
            return self::SUCCESS;
        }

        $total = 0;
        foreach ($companies as $companyId) {
            $count = $engine->computeCompany((int) $companyId);
            $this->info("Empresa {$companyId}: {$count} SKUs recalculados.");
            $total += $count;
        }

        $this->info("Total: {$total} SKUs recalculados.");
        return self::SUCCESS;
    }
}
