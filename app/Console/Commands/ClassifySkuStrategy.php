<?php

namespace App\Console\Commands;

use App\Services\SkuStrategyClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Job diário da tela de Segmentação. Roda por empresa e faz upsert em
 * `sku_strategy` — a tela nunca calcula isso na hora do request.
 */
class ClassifySkuStrategy extends Command
{
    protected $signature = 'sku:classify-strategy {--company= : Classifica apenas esta empresa}';

    protected $description = 'Classifica cada SKU em papel de precificação, ciclo de vida, saúde de estoque e posição competitiva';

    public function handle(SkuStrategyClassifier $classifier): int
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
            $count = $classifier->classifyCompany((int) $companyId);
            $this->info("Empresa {$companyId}: {$count} SKUs classificados.");
            $total += $count;
        }

        $this->info("Total: {$total} SKUs classificados.");
        return self::SUCCESS;
    }
}
