<?php

namespace App\Http\Controllers\Netshoes;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importações Netshoes (só canal — NÃO altera o catálogo).
 *
 * Recebe os exports .xlsx do painel Netshoes e grava a sobreposição de canal
 * nos produtos já existentes, cruzando pelo `sku` (que é igual ao "ID Sku" /
 * "Sku Seller" da Netshoes):
 *   - Produtos (export "Portal") -> netshoes_sku, netshoes_price(_from), netshoes_status
 *   - Estoque  (export "INVENTORY") -> netshoes_stock
 *
 * O parsing é 100% por streaming (openspout) para aguentar dezenas de milhares
 * de linhas sem estourar memória. Não cria produtos novos.
 */
class NetshoesImportController extends Controller
{
    public const TYPES = [
        'produtos' => [
            'title' => 'Importar Produtos Netshoes',
            'icon' => 'fa-solid fa-tags',
            'target' => 'products (netshoes_sku, netshoes_price, netshoes_status)',
            'key_label' => 'ID Sku  →  sku do produto',
            'value_label' => 'SKU Netshoes · Preço De/Por · Status',
            'description' => 'Export "Portal" da Netshoes (.xlsx). Cruza pelo SKU interno (coluna "ID Sku" = sku do produto) e grava o SKU Netshoes (para o Buy Box), o preço De/Por e o status do canal. Não cria produtos nem altera o catálogo.',
            'columns' => ['SKU Netshoes', 'ID Sku', 'Status', 'Preço De', 'Preço Por', 'Quantidade Estoque', 'Marca'],
        ],
        'estoque' => [
            'title' => 'Importar Estoque Netshoes',
            'icon' => 'fa-solid fa-boxes-stacked',
            'target' => 'products.netshoes_stock',
            'key_label' => 'Sku Seller  →  sku do produto',
            'value_label' => 'Quantidade disponível',
            'description' => 'Export "INVENTORY" da Netshoes (.xlsx). Cruza pelo SKU interno (coluna "Sku Seller" = sku do produto) e atualiza o estoque disponível no canal Netshoes.',
            'columns' => ['Sku Seller', 'Quantidade disponível'],
        ],
    ];

    /* ---------------- progresso ao vivo (cache de arquivo) ---------------- */
    private ?string $progressKey = null;
    private int $progTotal = 0;
    private int $progDone = 0;

    private function progressStore()
    {
        // Store de arquivo: visível entre processos e imune a transação de BD.
        return Cache::store('file');
    }

    private function initProgress(?string $token, int $total): void
    {
        $this->progressKey = $token ? 'nsh_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
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
        if (!$this->progressKey) {
            return;
        }
        try {
            $this->progressStore()->put($this->progressKey, array_merge([
                'status' => $status,
                'done' => $this->progDone,
                'total' => $this->progTotal,
            ], $extra), now()->addMinutes(30));
        } catch (\Throwable $e) {
            // progresso é best-effort; nunca derruba a importação
        }
    }

    /** Endpoint de polling do progresso (sem cache, JSON). */
    public function progress(string $token)
    {
        $key = 'nsh_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    /** Página de importação para um tipo. */
    public function show(string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return Inertia::render('Netshoes/Import', [
            'type' => $type,
            'config' => self::TYPES[$type],
            'allTypes' => collect(self::TYPES)->map(fn ($c, $k) => [
                'key' => $k,
                'title' => $c['title'],
                'icon' => $c['icon'],
            ])->values(),
        ]);
    }

    public function import(Request $request, string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:120000'], // até ~120 MB
        ]);

        // Validação por extensão (o mimes: pode falhar em .xlsx grandes na detecção).
        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls'], true)) {
            return redirect()->route('netshoes.show', ['type' => $type])
                ->with('error', 'Envie o arquivo .xlsx exportado pela Netshoes (recebido: .' . ($ext ?: '?') . ').');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $path = $request->file('file')->getRealPath();

        $this->initProgress(
            (string) $request->input('progress_token', '') ?: null,
            $this->countRows($path)
        );

        $summary = match ($type) {
            'produtos' => $this->importProdutos($path),
            'estoque' => $this->importEstoque($path),
        };

        $this->writeProgress('done', ['result' => $summary]);

        return redirect()->route('netshoes.show', ['type' => $type])
            ->with('importResult', $summary)
            ->with($summary['ok'] ? 'success' : 'error', $summary['message']);
    }

    /* ============================================================
     *  PRODUTOS (export "Portal") -> netshoes_sku / price / status
     * ============================================================ */
    private function importProdutos(string $path): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($this->readRows($path) as $row) {
                $rows++; $this->tick();

                $sku = $this->col($row, ['ID Sku', 'Id Sku', 'ID SKU']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                if (!$skuToId->has($sku)) { $notFound++; continue; }

                $payload = $this->prune([
                    'netshoes_sku' => $this->col($row, ['SKU Netshoes', 'Sku Netshoes']),
                    'netshoes_price' => $this->num($this->col($row, ['Preço Por', 'Preco Por'])),
                    'netshoes_price_from' => $this->num($this->col($row, ['Preço De', 'Preco De'])),
                    'netshoes_status' => $this->col($row, ['Status']),
                    'netshoes_synced_at' => now(),
                ]);

                DB::table('products')->where('id', $skuToId[$sku])->update($payload);
                $updated++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->fail($e);
        }

        return [
            'ok' => true,
            'rows' => $rows,
            'updated' => $updated,
            'created' => 0,
            'skipped' => $notFound + $skipped,
            'message' => "Produtos Netshoes: {$updated} atualizados, {$notFound} SKUs não encontrados no catálogo (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  ESTOQUE (export "INVENTORY") -> netshoes_stock
     * ============================================================ */
    private function importEstoque(string $path): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($this->readRows($path) as $row) {
                $rows++; $this->tick();

                $sku = $this->col($row, ['Sku Seller', 'SKU Seller', 'ID Sku', 'Sku']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                if (!$skuToId->has($sku)) { $notFound++; continue; }

                $qty = (int) round($this->num($this->col($row, ['Quantidade disponível', 'Quantidade Disponível', 'Quantidade'])) ?? 0);

                DB::table('products')->where('id', $skuToId[$sku])->update($this->prune([
                    'netshoes_stock' => $qty,
                    'netshoes_synced_at' => now(),
                ]));
                $updated++;
            }
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->fail($e);
        }

        return [
            'ok' => true,
            'rows' => $rows,
            'updated' => $updated,
            'created' => 0,
            'skipped' => $notFound + $skipped,
            'message' => "Estoque Netshoes: {$updated} produtos atualizados, {$notFound} SKUs não encontrados no catálogo (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  Leitura de XLSX por streaming (openspout) — 1ª linha = cabeçalho
     * ============================================================ */
    private function readRows(string $path): \Generator
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
                    $assoc = [];
                    $empty = true;
                    foreach ($header as $i => $h) {
                        if ($h === '') continue;
                        $val = $cells[$i] ?? null;
                        if ($val !== null && $val !== '') $empty = false;
                        $assoc[$h] = $val;
                    }
                    if ($empty) continue;
                    yield $assoc;
                }
                break; // apenas a primeira planilha
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * Conta as linhas de dados lendo a dimensão declarada no XML da planilha —
     * instantâneo, sem varrer o arquivo inteiro. Usado só para a barra de
     * progresso; se falhar, o total fica 0 (contador segue indeterminado).
     */
    private function countRows(string $path): int
    {
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) return 0;

            $sheetName = 'xl/worksheets/sheet1.xml';
            if ($zip->locateName($sheetName) === false) {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $n)) { $sheetName = $n; break; }
                }
            }
            $fp = $zip->getStream($sheetName);
            $head = $fp ? fread($fp, 4096) : '';
            if ($fp) fclose($fp);
            $zip->close();

            if (is_string($head) && preg_match('/<dimension\s+ref="[A-Z]+\d+:[A-Z]+(\d+)"/', $head, $m)) {
                return max(0, (int) $m[1] - 1); // desconta o cabeçalho
            }
        } catch (\Throwable $e) {
            // ignora — total indeterminado
        }
        return 0;
    }

    /** Colunas reais da tabela products (cache por request). */
    private function productColumns(): array
    {
        static $cols = null;
        if ($cols === null) {
            try {
                $cols = Schema::getColumnListing('products');
            } catch (\Throwable $e) {
                $cols = [];
            }
        }
        return $cols;
    }

    /** Remove do payload chaves que não existem em products (schema resiliente). */
    private function prune(array $payload): array
    {
        $cols = $this->productColumns();
        return empty($cols) ? $payload : array_intersect_key($payload, array_flip($cols));
    }

    /** Lê o valor de uma coluna tolerando variações de cabeçalho e retorna string trim. */
    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim((string) $header)) === mb_strtolower($c)) {
                    if ($value === null) return null;
                    return trim($this->cellToString($value));
                }
            }
        }
        return null;
    }

    /** Converte um valor de célula (string, número, DateTime) para texto. */
    private function cellToString($value): string
    {
        if (is_string($value)) return $value;
        if (is_bool($value)) return $value ? '1' : '0';
        if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d H:i:s');
        if (is_float($value)) {
            // evita notação científica e ".0" em inteiros que vieram como float
            return rtrim(rtrim(sprintf('%.4f', $value), '0'), '.');
        }
        return (string) $value;
    }

    /** Normaliza número aceitando ponto (Netshoes) ou vírgula (BR). */
    private function num($value): ?float
    {
        if ($value === null) return null;
        if (is_int($value) || is_float($value)) return (float) $value;
        $v = trim((string) $value);
        if ($v === '') return null;
        $v = str_replace(['R$', ' ', "\xC2\xA0"], '', $v);
        $hasComma = strpos($v, ',') !== false;
        $hasDot = strpos($v, '.') !== false;
        if ($hasComma && $hasDot) {
            // 1.234,56 -> 1234.56 (ponto = milhar, vírgula = decimal)
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif ($hasComma) {
            $v = str_replace(',', '.', $v);
        }
        return is_numeric($v) ? (float) $v : null;
    }

    private function fail(\Throwable $e): array
    {
        return [
            'ok' => false,
            'rows' => 0, 'updated' => 0, 'created' => 0, 'skipped' => 0,
            'message' => 'Falha na importação (nada foi gravado): ' . $e->getMessage(),
        ];
    }
}
