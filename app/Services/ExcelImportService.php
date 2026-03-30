<?php

namespace App\Services;

use App\Imports\FeesImport;
use App\Imports\ProductsImport;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Service para coordenar a importação multicanal da planilha Excel.
 */
class ExcelImportService
{
    /**
     * Importa a planilha completa chamando as classes específicas para cada sheet.
     */
    public function importPricingSpreadsheet(string $filePath, int $companyId)
    {
        // 1. Importa Taxas (Sheet 0)
        Excel::import(new FeesImport($companyId), $filePath, null, \Maatwebsite\Excel\Excel::XLSX);

        // 2. Importa Produtos (Sheet 1)
        Excel::import(new ProductsImport($companyId), $filePath, null, \Maatwebsite\Excel\Excel::XLSX);

        // 3. Importa Estoque e Custos (Sheet 2)
        Excel::import(new StockImport($companyId), $filePath, null, \Maatwebsite\Excel\Excel::XLSX);

        // 4. Dispara o Processamento Assíncrono da Análise de Promoção (Sheet 3 Logic)
        \App\Jobs\ProcessPromotionAnalysisJob::dispatch($companyId);

        return true;
    }
}
