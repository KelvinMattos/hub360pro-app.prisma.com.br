<?php

namespace App\Imports;

use App\Models\StockHistory;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Carbon\Carbon;

class StockImport implements ToModel, WithHeadingRow
{
    private $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function model(array $row)
    {
        $product = Product::where('company_id', $this->companyId)
            ->where('sku', $row['codigo'])
            ->first();

        if (!$product)
            return null;

        return new StockHistory([
            'product_id' => $product->id,
            'quantity' => $row['estoque'] ?? 0,
            'cost_price' => $row['custo'] ?? 0,
            'received_at' => $this->parseDate($row['data_entrada'] ?? null),
        ]);
    }

    private function parseDate($date)
    {
        if (!$date)
            return now();
        // Lógica para tratar datas vindas do Excel
        try {
            return Carbon::instance(\PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date));
        }
        catch (\Exception $e) {
            return now();
        }
    }
}
