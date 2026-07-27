<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importação de PREÇOS DE MERCADO (concorrência) por planilha — o feed do
 * módulo de monitoramento enquanto não há coleta automática (API/scraper).
 *
 * Aceita .xlsx e .csv. Cruza pelo `sku` e, como o SKU Netshoes é universal
 * entre sellers, também pelo `netshoes_sku` — permitindo alimentar direto a
 * partir de um export de Buy Box da Netshoes. Grava market_price / market_seller
 * / market_source='import' / market_checked_at. Não cria produtos.
 */
class MarketPriceImportController extends Controller
{
    private ?string $progressKey = null;
    private int $progTotal = 0;
    private int $progDone = 0;

    public function form()
    {
        return Inertia::render('Monitoring/MarketImport');
    }

    public function progress(string $token)
    {
        $key = 'mkt_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
        ]);

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return redirect()->route('monitoring.market.form')
                ->with('error', 'Envie um arquivo .xlsx ou .csv (recebido: .' . ($ext ?: '?') . ').');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $companyId = Auth::user()->company_id;
        $path = $request->file('file')->getRealPath();
        $isXlsx = in_array($ext, ['xlsx', 'xls'], true);

        $this->initProgress(
            (string) $request->input('progress_token', '') ?: null,
            $isXlsx ? $this->countXlsxRows($path) : $this->countCsvRows($path)
        );

        // Mapas de resolução: sku -> id e netshoes_sku -> id.
        $base = DB::table('products');
        if (Schema::hasColumn('products', 'company_id')) {
            $base->where('company_id', $companyId);
        }
        $skuMap = (clone $base)->whereNotNull('sku')->pluck('id', 'sku');
        $nshMap = Schema::hasColumn('products', 'netshoes_sku')
            ? (clone $base)->whereNotNull('netshoes_sku')->pluck('id', 'netshoes_sku')
            : collect();

        $rows = 0; $updated = 0; $notFound = 0; $skipped = 0;
        $rowsGen = $isXlsx ? $this->readXlsx($path) : $this->readCsv($path);

        DB::beginTransaction();
        try {
            foreach ($rowsGen as $row) {
                $rows++; $this->tick();

                $sku = $this->col($row, ['SKU', 'Sku', 'Código', 'Codigo', 'SKU Netshoes', 'Sku Netshoes', 'Sku Seller', 'ID Sku']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                $id = $skuMap[$sku] ?? $nshMap[$sku] ?? null;
                if ($id === null) { $notFound++; continue; }

                $price = $this->num($this->col($row, ['Preço Mercado', 'Preco Mercado', 'Preço Concorrente', 'Preco Concorrente', 'Buy Box', 'Menor Preço', 'Preço', 'Preco', 'Preço Por']));
                if ($price === null) { $skipped++; continue; }

                $payload = $this->prune([
                    'market_price' => $price,
                    'market_seller' => $this->col($row, ['Vendedor', 'Seller', 'Ganhador', 'Loja']),
                    'market_source' => 'import',
                    'market_checked_at' => now(),
                ]);

                DB::table('products')->where('id', $id)->update($payload);
                $updated++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $summary = [
                'ok' => false, 'rows' => 0, 'updated' => 0, 'created' => 0, 'skipped' => 0,
                'message' => 'Falha na importação (nada foi gravado): ' . $e->getMessage(),
            ];
            $this->writeProgress('done', ['result' => $summary]);
            return redirect()->route('monitoring.market.form')->with('importResult', $summary)->with('error', $summary['message']);
        }

        $summary = [
            'ok' => true,
            'rows' => $rows,
            'updated' => $updated,
            'created' => 0,
            'skipped' => $notFound + $skipped,
            'message' => "Preços de mercado: {$updated} atualizados, {$notFound} SKUs não encontrados (de {$rows} linhas).",
        ];
        $this->writeProgress('done', ['result' => $summary]);

        return redirect()->route('monitoring.market.form')
            ->with('importResult', $summary)->with('success', $summary['message']);
    }

    /* ----------------------------- leitura ----------------------------- */

    private function readXlsx(string $path): \Generator
    {
        $reader = new XlsxReader();
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $header = null;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    if ($header === null) {
                        $header = array_map(fn ($h) => trim((string) $h), $cells);
                        continue;
                    }
                    $assoc = []; $empty = true;
                    foreach ($header as $i => $h) {
                        if ($h === '') continue;
                        $val = $cells[$i] ?? null;
                        if ($val !== null && $val !== '') $empty = false;
                        $assoc[$h] = is_string($val) ? $val : ($val instanceof \DateTimeInterface ? $val->format('Y-m-d') : (is_float($val) ? rtrim(rtrim(sprintf('%.4f', $val), '0'), '.') : (is_scalar($val) ? (string) $val : null)));
                    }
                    if (!$empty) yield $assoc;
                }
                break;
            }
        } finally {
            $reader->close();
        }
    }

    private function readCsv(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) return;
        try {
            $first = fgets($fh);
            $delim = (substr_count((string) $first, ';') >= substr_count((string) $first, ',')) ? ';' : ',';
            rewind($fh);
            $header = fgetcsv($fh, 0, $delim);
            if ($header === false) return;
            $header = array_map(fn ($h) => trim($this->toUtf8((string) $h)), $header);
            while (($data = fgetcsv($fh, 0, $delim)) !== false) {
                if ($data === [null] || $data === ['']) continue;
                $assoc = [];
                foreach ($header as $i => $h) {
                    if ($h === '') continue;
                    $assoc[$h] = array_key_exists($i, $data) && $data[$i] !== null ? $this->toUtf8((string) $data[$i]) : null;
                }
                yield $assoc;
            }
        } finally {
            fclose($fh);
        }
    }

    private function toUtf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    private function countCsvRows(string $path): int
    {
        $n = 0;
        $fh = @fopen($path, 'r');
        if ($fh === false) return 0;
        while (fgets($fh) !== false) $n++;
        fclose($fh);
        return max(0, $n - 1);
    }

    private function countXlsxRows(string $path): int
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return 0;
            $sheetName = 'xl/worksheets/sheet1.xml';
            if ($zip->locateName($sheetName) === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $nm = $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nm)) { $sheetName = $nm; break; }
                }
            }
            $fp = $zip->getStream($sheetName);
            $head = $fp ? fread($fp, 4096) : '';
            if ($fp) fclose($fp);
            $zip->close();
            if (is_string($head) && preg_match('/<dimension\s+ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $head, $m)) {
                return max(0, (int) $m[1] - 1);
            }
        } catch (\Throwable $e) {
        }
        return 0;
    }

    /* ----------------------------- helpers ----------------------------- */

    private function productColumns(): array
    {
        static $cols = null;
        if ($cols === null) {
            try { $cols = Schema::getColumnListing('products'); }
            catch (\Throwable $e) { $cols = []; }
        }
        return $cols;
    }

    private function prune(array $payload): array
    {
        $cols = $this->productColumns();
        return empty($cols) ? $payload : array_intersect_key($payload, array_flip($cols));
    }

    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim((string) $header)) === mb_strtolower($c)) {
                    return $value === null ? null : trim((string) $value);
                }
            }
        }
        return null;
    }

    private function num($value): ?float
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        if ($v === '') return null;
        $v = str_replace(['R$', ' ', "\xC2\xA0"], '', $v);
        $hasComma = strpos($v, ',') !== false;
        $hasDot = strpos($v, '.') !== false;
        if ($hasComma && $hasDot) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif ($hasComma) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    /* ----------------------------- progresso ----------------------------- */

    private function initProgress(?string $token, int $total): void
    {
        $this->progressKey = $token ? 'mkt_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
        $this->progTotal = $total;
        $this->progDone = 0;
        $this->writeProgress('processing');
    }

    private function tick(): void
    {
        $this->progDone++;
        if ($this->progressKey && ($this->progDone % 200 === 0)) {
            $this->writeProgress('processing');
        }
    }

    private function writeProgress(string $status, array $extra = []): void
    {
        if (!$this->progressKey) return;
        try {
            Cache::store('file')->put($this->progressKey, array_merge([
                'status' => $status, 'done' => $this->progDone, 'total' => $this->progTotal,
            ], $extra), now()->addMinutes(30));
        } catch (\Throwable $e) {
        }
    }
}
