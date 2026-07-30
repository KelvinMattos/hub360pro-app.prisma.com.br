<?php

namespace App\Jobs;

use App\Services\Monitoring\MarketPriceImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Roda a importação de preços de mercado fora do request HTTP — arquivos
 * grandes (40k+ linhas) eram cortados pelo timeout de borda do Cloudflare
 * (~100s) quando processados de forma síncrona. Ver
 * MarketPriceImportProcessor para o motivo completo.
 */
class ImportMarketPricesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900; // 15 min — 40k+ linhas em lotes de 500

    public function __construct(
        private int $companyId,
        private string $storedPath,
        private bool $isXlsx,
        private ?string $progressToken,
    ) {
    }

    public function handle(MarketPriceImportProcessor $processor): void
    {
        $path = Storage::path($this->storedPath);

        if (!file_exists($path)) {
            Log::error("ImportMarketPricesJob: arquivo não encontrado em {$path} (company {$this->companyId}).");
            return;
        }

        $total = $this->isXlsx ? $processor->countXlsxRows($path) : $processor->countCsvRows($path);

        try {
            $processor->process($this->companyId, $path, $this->isXlsx, $this->progressToken, $total);
        } finally {
            Storage::delete($this->storedPath);
        }
    }
}
