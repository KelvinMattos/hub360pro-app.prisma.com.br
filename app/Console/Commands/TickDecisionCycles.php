<?php

namespace App\Console\Commands;

use App\Models\DecisionCycle;
use App\Services\DecisionCycleEngine;
use Illuminate\Console\Command;

/**
 * Roda 1x/dia: para cada ciclo 'running', verifica o freio, aplica o próximo
 * lote, ou conclui e mede o ROI — ver DecisionCycleEngine::tick().
 */
class TickDecisionCycles extends Command
{
    protected $signature = 'decision-cycles:tick';

    protected $description = 'Avança os ciclos de decisão em execução (freio, próximo lote ou conclusão + ROI)';

    public function handle(DecisionCycleEngine $engine): int
    {
        $cycles = DecisionCycle::where('status', DecisionCycle::STATUS_RUNNING)->get();

        if ($cycles->isEmpty()) {
            $this->info('Nenhum ciclo em execução.');
            return self::SUCCESS;
        }

        foreach ($cycles as $cycle) {
            $result = $engine->tick($cycle);
            $this->info("Ciclo #{$cycle->id}: {$result['action']}" . (isset($result['reason']) ? " ({$result['reason']})" : ''));
        }

        return self::SUCCESS;
    }
}
