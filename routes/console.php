<?php
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

// Automação Dinâmica Inteligente
Schedule::command('meli:refresh-tokens')->everyThirtyMinutes(); // Renova tokens que estão prestes a expirar.
Schedule::command('orders:sync')->hourly(); // Extrai todos os novos pedidos periodicamente.
Schedule::command('products:sync')->twiceDaily(4, 16); // Busca por inconsistências de produtos a cada 12h.
Schedule::command('sku:classify-strategy')->daily(); // Recalcula a segmentação de SKU (papel, ciclo de vida, estoque, competitividade).
Schedule::command('decision-cycles:tick')->daily(); // Avança ciclos de decisão em execução (freio, lote gradual, conclusão + ROI).