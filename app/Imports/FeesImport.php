<?php

namespace App\Imports;

use App\Models\MarketplaceFee;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class FeesImport implements ToModel, WithHeadingRow
{
    private $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function model(array $row)
    {
        // Mapeamento baseado na descrição da Sheet "TAXAS"
        return new MarketplaceFee([
            'company_id' => $this->companyId,
            'platform' => $row['canal'] ?? $row['plataforma'],
            'commission_percent' => $row['comissao'] ?? 0,
            'tax_percent' => $row['imposto'] ?? 0,
            'fixed_fee_rules' => $this->parseFixedFees($row),
        ]);
    }

    private function parseFixedFees(array $row): array
    {
        // Lógica para transformar colunas de taxas fixas da planilha em JSON
        // Exemplo: Se a planilha tiver faixas de preço
        return [
            ['min' => 0, 'max' => 78.99, 'fee' => 6.00],
            ['min' => 79, 'max' => 99999, 'fee' => 0.00],
        ];
    }
}
