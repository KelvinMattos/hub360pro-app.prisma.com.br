<?php

namespace App\Services\Sales;

use App\Support\SalesChannels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa o relatório "Diário de Vendas" que o cliente mantinha manualmente
 * em planilha (uma aba por canal, uma linha por dia — ver CLAUDE.md e
 * App\Support\SalesChannels). Aceita .xls (legado, formato real do arquivo
 * do cliente) e .xlsx — por isso usa PhpSpreadsheet (openspout não lê .xls),
 * detectando o formato pelo conteúdo do arquivo, não pela extensão.
 *
 * O ano/mês de cada linha vem da própria célula de data, nunca do nome do
 * arquivo/aba — mais robusto (mesma lição do CLAUDE.md §5.1 sobre
 * date_created vs created_at: nunca inferir data por metadado quando o dado
 * real está ali na linha).
 */
class SalesChannelDailyImportService
{
    public function import(int $companyId, string $path, string $sourceFile, ?callable $onProgress = null): array
    {
        if (!Schema::hasTable('channel_sales_daily')) {
            return $this->fail('Tabela channel_sales_daily não existe — rode as migrations antes de importar.');
        }

        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($path);
        } catch (\Throwable $e) {
            return $this->fail('Não foi possível ler o arquivo: ' . $e->getMessage());
        }

        $sheets = $spreadsheet->getAllSheets();
        $totalSheets = count($sheets);
        $sheetsProcessed = 0;

        $recognized = 0;
        $ignoredSheets = [];
        $rowsImported = 0;
        $rowsSkipped = 0;
        $now = now();

        foreach ($sheets as $sheet) {
            $sheetsProcessed++;
            if ($onProgress) {
                $onProgress($sheetsProcessed, $totalSheets);
            }

            $channel = SalesChannels::fromSheetName($sheet->getTitle());
            if ($channel === null) {
                $ignoredSheets[] = $sheet->getTitle();
                continue;
            }
            $recognized++;

            [$rows, $skipped] = $this->parseSheet($sheet, $companyId, $channel, $sourceFile, $now);
            $rowsImported += $rows;
            $rowsSkipped += $skipped;
        }

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);

        $msg = "Vendas diárias: {$rowsImported} dias gravados em {$recognized} canal(is) reconhecido(s)"
            . " de {$totalSheets} aba(s)";
        if (!empty($ignoredSheets)) {
            $msg .= ', ' . count($ignoredSheets) . ' aba(s) não reconhecida(s) ignorada(s): ' . implode(', ', $ignoredSheets);
        }
        $msg .= '.';

        return [
            'ok' => true,
            'sheets_total' => $totalSheets,
            'sheets_recognized' => $recognized,
            'sheets_ignored' => $ignoredSheets,
            'rows_imported' => $rowsImported,
            'rows_skipped' => $rowsSkipped,
            'message' => $msg,
        ];
    }

    /** @return array{0:int,1:int} [linhas gravadas, linhas ignoradas] */
    private function parseSheet(Worksheet $sheet, int $companyId, string $channel, string $sourceFile, $now): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $highestColIdx = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $headerRow = null;
        for ($r = 1; $r <= min($highestRow, 10); $r++) {
            $a1 = trim((string) $sheet->getCell([1, $r])->getValue());
            if ($this->normalize($a1) === 'DATA') {
                $headerRow = $r;
                break;
            }
        }
        if ($headerRow === null) {
            // Aba reconhecida como canal mas sem o cabeçalho esperado — não
            // inventa onde ficam as colunas, simplesmente não grava nada dela.
            return [0, 0];
        }

        $headerMap = [];
        for ($c = 1; $c <= $highestColIdx; $c++) {
            $label = trim((string) $sheet->getCell([$c, $headerRow])->getValue());
            $field = $this->matchHeader($label);
            if ($field !== null) {
                $headerMap[$c] = $field;
            }
        }

        $rows = 0;
        $skipped = 0;
        $upserts = [];

        for ($r = $headerRow + 1; $r <= $highestRow; $r++) {
            $date = $this->parseDateCell($sheet->getCell([1, $r]));
            if ($date === null) {
                // Linha em branco ou a linha "TOTAIS" no fim — não é dado diário.
                $skipped++;
                continue;
            }

            $values = [
                'gross_value' => 0.0, 'paid_value' => 0.0, 'canceled_value' => 0.0,
                'fees' => 0.0, 'shipping_cost' => 0.0, 'net_value' => 0.0, 'orders_count' => 0,
            ];
            foreach ($headerMap as $c => $field) {
                // getCalculatedValue() em vez de getValue(): várias colunas do
                // relatório do cliente são fórmulas (ex.: PAGOS = EFETUADOS -
                // CANCELADOS), não números literais — getValue() devolveria a
                // string da fórmula em vez do resultado.
                $raw = $sheet->getCell([$c, $r])->getCalculatedValue();
                $num = is_numeric($raw) ? (float) $raw : 0.0;
                $values[$field] = $field === 'orders_count' ? (int) round($num) : $num;
            }

            $upserts[] = array_merge($values, [
                'company_id' => $companyId,
                'channel' => $channel,
                'sale_date' => $date->format('Y-m-d'),
                'source_file' => $sourceFile,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rows++;
        }

        if (!empty($upserts)) {
            foreach (array_chunk($upserts, 200) as $chunk) {
                DB::table('channel_sales_daily')->upsert(
                    $chunk,
                    ['company_id', 'channel', 'sale_date'],
                    ['gross_value', 'paid_value', 'canceled_value', 'fees', 'shipping_cost', 'net_value', 'orders_count', 'source_file', 'updated_at']
                );
            }
        }

        return [$rows, $skipped];
    }

    /** Datas vêm como serial do Excel (caso real do arquivo do cliente) ou, defensivamente, como texto d/m/Y. */
    private function parseDateCell(\PhpOffice\PhpSpreadsheet\Cell\Cell $cell): ?\DateTimeImmutable
    {
        $raw = $cell->getValue();
        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            try {
                $dt = ExcelDate::excelToDateTimeObject((float) $raw);
                return \DateTimeImmutable::createFromMutable($dt)->setTime(0, 0);
            } catch (\Throwable $e) {
                return null;
            }
        }

        $str = trim((string) $raw);
        foreach (['!d/m/Y', '!d/m/y', '!Y-m-d'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $str);
            if ($dt !== false) {
                return $dt;
            }
        }

        return null;
    }

    /** Casa o cabeçalho da coluna tolerando acento/caixa (nunca por posição fixa). */
    private function matchHeader(string $label): ?string
    {
        $n = $this->normalize($label);
        if ($n === '') {
            return null;
        }

        return match (true) {
            str_contains($n, 'PEDIDOS EFETUADOS') => 'gross_value',
            str_contains($n, 'PEDIDOS PAGOS') => 'paid_value',
            str_contains($n, 'PEDIDOS CANCELADOS') => 'canceled_value',
            str_contains($n, 'TARIFAS DE VENDA') => 'fees',
            str_contains($n, 'CUSTO') && str_contains($n, 'FRETE') => 'shipping_cost',
            str_contains($n, 'TOTAL LIQUIDO') => 'net_value',
            str_contains($n, 'NUMERO DE PEDIDOS') => 'orders_count',
            default => null,
        };
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(trim(Str::ascii($value)));
    }

    private function fail(string $message): array
    {
        return [
            'ok' => false,
            'sheets_total' => 0, 'sheets_recognized' => 0, 'sheets_ignored' => [],
            'rows_imported' => 0, 'rows_skipped' => 0,
            'message' => $message,
        ];
    }
}
