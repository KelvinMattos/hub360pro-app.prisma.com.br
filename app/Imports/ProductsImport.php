<?php

namespace App\Imports;

use App\Models\Product;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class ProductsImport implements ToModel, WithHeadingRow, WithChunkReading
{
    private $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function model(array $row)
    {
        // Mapeamento baseado na Sheet "PV DE-POR"
        return Product::updateOrCreate(
        [
            'company_id' => $this->companyId,
            'sku' => $row['codigo'] ?? $row['sku']
        ],
        [
            'name' => $row['produto'] ?? $row['nome'],
            'brand' => $row['marca'] ?? null,
            'base_price' => $row['preco_venda'] ?? 0,
        ]
        );
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
