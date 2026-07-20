<?php

namespace App\Http\Controllers\Magazord;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Importações Magazord.
 *
 * Recebe os modelos de exportação do Magazord (CSV latin-1, delimitado por ";",
 * números no padrão BR) e alimenta o banco do PrismaHUB:
 *   - Estoque              -> products.stock_quantity   (chave: SKU/Código Der.)
 *   - Custos de Produtos   -> products.cost_price        (chave: Código)
 *   - Preços de Venda      -> products.sale_price        (chave: Código)
 *   - Vendas               -> orders                     (chave: Pedido Id/external_id)
 *
 * Todo o parsing acontece por streaming (generator nativo com fgetcsv) e as
 * escritas são feitas em transação, para suportar arquivos grandes (dezenas de
 * milhares de linhas) sem estourar memória.
 */
class MagazordImportController extends Controller
{
    /** Configuração de cada tipo de importação (usada pela página e pela validação). */
    public const TYPES = [
        'estoque' => [
            'title' => 'Importar Estoque',
            'icon' => 'fa-solid fa-boxes-stacked',
            'target' => 'products.stock_quantity',
            'key_label' => 'Produto/Derivação Código Der.',
            'value_label' => 'Quantidade Física',
            'description' => 'Atualiza a quantidade em estoque dos produtos, cruzando pelo SKU (coluna "Produto/Derivação Código Der.").',
            'columns' => ['Id Dep.', 'Depósito', 'Produto/Derivação Código Der.', 'Produto/Derivação Marca', 'Quantidade Física', 'Quantidade Disp. Venda', 'Custo Médio de Estoque'],
            'can_create' => false,
        ],
        'custos' => [
            'title' => 'Importar Custos de Produtos',
            'icon' => 'fa-solid fa-money-bill-trend-up',
            'target' => 'products.cost_price',
            'key_label' => 'Código',
            'value_label' => 'Valor Atual',
            'description' => 'Atualiza o custo unitário (CMV) dos produtos, cruzando pelo SKU (coluna "Código"). Usa "Valor Atual" como custo por unidade.',
            'columns' => ['Id Der.', 'Código', 'Produto', 'Qtd Física', 'Valor Atual', 'Valor Estoque', 'Produto Ativo'],
            'can_create' => true,
        ],
        'precos' => [
            'title' => 'Importar Preços de Venda',
            'icon' => 'fa-solid fa-tags',
            'target' => 'products.sale_price',
            'key_label' => 'Produto Código / Código',
            'value_label' => 'Preço Venda / Preço',
            'description' => 'Atualiza o preço de venda dos produtos, cruzando pelo código do produto. Detecta automaticamente as colunas de código e de preço do modelo exportado.',
            'columns' => ['Produto Código (ou Código)', 'Produto Nome', 'Preço Antigo', 'Preço Venda (ou Preço)'],
            'can_create' => false,
        ],
        'vendas' => [
            'title' => 'Importar Vendas',
            'icon' => 'fa-solid fa-cart-shopping',
            'target' => 'orders',
            'key_label' => 'Pedido Id',
            'value_label' => 'Valor Total Pedido',
            'description' => 'Cria/atualiza pedidos, cruzando pelo "Pedido Id" (external_id). Importa cliente, documento, canal, situação, data e valores. O arquivo é a nível de pedido (sem itens), então não gera linhas de produto.',
            'columns' => ['Pedido Id', 'Código', 'Data/Hora', 'Cliente', 'CPF/CNPJ', 'Situação', 'Marketplace', 'Forma de Pagamento', 'Valor Total Pedido'],
            'can_create' => true,
        ],
    ];

    /** Renderiza a página de importação para um tipo. */
    public function show(string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return Inertia::render('Magazord/Import', [
            'type' => $type,
            'config' => self::TYPES[$type],
            'allTypes' => collect(self::TYPES)->map(fn ($c, $k) => [
                'key' => $k,
                'title' => $c['title'],
                'icon' => $c['icon'],
            ])->values(),
        ]);
    }

    /** Processa o upload e grava no banco. */
    public function import(Request $request, string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:102400', // 100 MB
            'create_missing' => 'sometimes|boolean',
        ], [
            'file.mimes' => 'Envie o arquivo em formato .csv. Se o Magazord exportou .xls, reexporte como CSV (o .xls do Magazord costuma vir como HTML e não pode ser lido).',
        ]);

        $path = $request->file('file')->getRealPath();
        $createMissing = (bool) $request->boolean('create_missing');

        // Rejeita o .xls do Magazord que na verdade vem como HTML (erro de export).
        $head = @file_get_contents($path, false, null, 0, 64);
        if ($head !== false && stripos($head, '<html') !== false) {
            return back()->with('error', 'O arquivo enviado é um HTML (provável erro de exportação do Magazord), não uma planilha. Reexporte o relatório como CSV e tente novamente.');
        }

        $summary = match ($type) {
            'estoque' => $this->importEstoque($this->readRows($path)),
            'custos' => $this->importCustos($this->readRows($path), $createMissing),
            'precos' => $this->importPrecos($this->readRows($path)),
            'vendas' => $this->importVendas($this->readRows($path), $createMissing),
        };

        return back()->with('importResult', $summary)
            ->with('success', $summary['message']);
    }

    /* ============================================================
     *  Leitura de CSV (latin-1, delimitador ";", cabeçalho na 1ª linha)
     *  Parsing nativo por streaming — sem dependências externas — para
     *  suportar arquivos grandes sem carregar tudo em memória.
     * ============================================================ */
    private function readRows(string $path): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) {
            throw new \RuntimeException('não foi possível abrir o arquivo enviado.');
        }
        try {
            $header = fgetcsv($fh, 0, ';');
            if ($header === false) {
                return;
            }
            $header = array_map(fn ($h) => trim($this->toUtf8((string) $h)), $header);
            while (($data = fgetcsv($fh, 0, ';')) !== false) {
                if ($data === [null] || ($data === [''] )) {
                    continue; // linha em branco
                }
                $row = [];
                foreach ($header as $i => $h) {
                    if ($h === '') continue;
                    $row[$h] = array_key_exists($i, $data) && $data[$i] !== null
                        ? $this->toUtf8((string) $data[$i]) : null;
                }
                yield $row;
            }
        } finally {
            fclose($fh);
        }
    }

    /** Converte um valor para UTF-8 (o Magazord exporta em ISO-8859-1). */
    private function toUtf8(string $v): string
    {
        return mb_check_encoding($v, 'UTF-8') ? $v : mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    }

    /** Normaliza número no padrão BR ("1.674,14" -> 1674.14). */
    private function brNumber($value): ?float
    {
        if ($value === null) return null;
        $v = trim((string) $value);
        if ($v === '') return null;
        $v = str_replace(['R$', ' ', "\xC2\xA0"], '', $v);
        // remove separador de milhar (.) e troca decimal (,) por ponto
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    /** Lê o valor de uma coluna tolerando variações de cabeçalho. */
    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim($header)) === mb_strtolower($c)) {
                    return $value === null ? null : trim((string) $value);
                }
            }
        }
        return null;
    }

    /* ============================================================
     *  ESTOQUE -> products.stock_quantity
     * ============================================================ */
    private function importEstoque(iterable $records): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $notFound = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Produto/Derivação Código Der.', 'Código Der.', 'Código']);
                if ($sku === null || $sku === '') continue;
                $qtyRaw = $this->col($row, ['Quantidade Física', 'Quantidade Disp. Venda']);
                $qty = (int) round($this->brNumber($qtyRaw) ?? 0);

                if ($skuToId->has($sku)) {
                    Product::where('id', $skuToId[$sku])->update(['stock_quantity' => $qty]);
                    $updated++;
                } else {
                    $notFound++;
                }
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
            'skipped' => $notFound,
            'message' => "Estoque importado: {$updated} produtos atualizados, {$notFound} SKUs não encontrados (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  CUSTOS -> products.cost_price
     * ============================================================ */
    private function importCustos(iterable $records, bool $createMissing): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $created = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Código']);
                if ($sku === null || $sku === '') { $skipped++; continue; }
                $cost = $this->brNumber($this->col($row, ['Valor Atual']));
                if ($cost === null) { $skipped++; continue; }

                if ($skuToId->has($sku)) {
                    Product::where('id', $skuToId[$sku])->update(['cost_price' => $cost]);
                    $updated++;
                } elseif ($createMissing) {
                    $ativo = mb_strtolower((string) $this->col($row, ['Produto Ativo'])) === 'sim';
                    $p = Product::create([
                        'company_id' => $companyId,
                        'sku' => $sku,
                        'title' => $this->col($row, ['Produto']) ?: $sku,
                        'cost_price' => $cost,
                        'status' => $ativo ? 'active' : 'inactive',
                    ]);
                    $skuToId[$sku] = $p->id;
                    $created++;
                } else {
                    $skipped++;
                }
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
            'created' => $created,
            'skipped' => $skipped,
            'message' => "Custos importados: {$updated} atualizados, {$created} criados, {$skipped} ignorados (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  PREÇOS DE VENDA -> products.sale_price
     * ============================================================ */
    private function importPrecos(iterable $records): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Produto Código', 'Código', 'Código Produto']);
                if ($sku === null || $sku === '') { $skipped++; continue; }
                $price = $this->brNumber($this->col($row, ['Preço Venda', 'Preço', 'Preço Por']));
                if ($price === null) { $skipped++; continue; }

                if ($skuToId->has($sku)) {
                    Product::where('id', $skuToId[$sku])->update(['sale_price' => $price]);
                    $updated++;
                } else {
                    $notFound++;
                }
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
            'message' => "Preços importados: {$updated} atualizados, {$notFound} SKUs não encontrados (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  VENDAS -> orders
     * ============================================================ */
    private function importVendas(iterable $records, bool $createMissing): array
    {
        $companyId = Auth::user()->company_id;
        $updated = 0; $created = 0; $skipped = 0; $rows = 0;

        // O schema de `orders` variou entre migrations; detectamos as colunas
        // reais e mapeamos cada campo para a primeira coluna existente.
        $cols = Schema::getColumnListing('orders');
        $pick = fn (array $cands) => collect($cands)->first(fn ($c) => in_array($c, $cols, true));
        $keyCol = $pick(['external_id', 'ml_order_id']);           // identificador único do pedido
        $nameCol = $pick(['customer_name', 'buyer_nickname']);
        $emailCol = $pick(['customer_email', 'buyer_email']);
        $docCol = $pick(['customer_doc', 'billing_doc_number']);
        $channelCol = $pick(['selling_channel']);
        $payCol = $pick(['payment_method', 'payment_status']);
        $statusCol = $pick(['status']);
        $totalCol = $pick(['total_amount']);
        $paidCol = $pick(['total_paid_amount']);
        $dateCol = $pick(['date_created', 'order_date']);
        $hasCompany = in_array('company_id', $cols, true);
        $hasTimestamps = in_array('created_at', $cols, true);

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $externalId = $this->col($row, ['Pedido Id']);
                if ($externalId === null || $externalId === '') { $skipped++; continue; }

                $total = $this->brNumber($this->col($row, ['Valor Total Pedido', 'Valor Total'])) ?? 0;
                $payload = [$keyCol => $externalId];
                if ($nameCol)   $payload[$nameCol]   = $this->col($row, ['Cliente']);
                if ($emailCol)  $payload[$emailCol]  = $this->col($row, ['E-mail']);
                if ($docCol)    $payload[$docCol]    = $this->col($row, ['CPF/CNPJ']);
                if ($channelCol) $payload[$channelCol] = $this->col($row, ['Marketplace']) ?: $this->col($row, ['Loja']);
                if ($payCol)    $payload[$payCol]    = $this->col($row, ['Forma de Pagamento']);
                if ($statusCol) $payload[$statusCol] = $this->mapStatus($this->col($row, ['Situação - Transporte']), $this->col($row, ['Situação']));
                if ($totalCol)  $payload[$totalCol]  = $total;
                if ($paidCol)   $payload[$paidCol]   = $total;
                if ($dateCol)   $payload[$dateCol]   = $this->parseDate($this->col($row, ['Data/Hora', 'Data Aprovação']));

                $query = DB::table('orders')->where($keyCol, $externalId);
                if ($hasCompany) $query->where('company_id', $companyId);
                $existing = $query->first();

                if ($existing) {
                    if ($hasTimestamps) $payload['updated_at'] = now();
                    (clone $query)->update($payload);
                    $updated++;
                } elseif ($createMissing) {
                    if ($hasCompany) $payload['company_id'] = $companyId;
                    if ($hasTimestamps) { $payload['created_at'] = now(); $payload['updated_at'] = now(); }
                    DB::table('orders')->insert($payload);
                    $created++;
                } else {
                    $skipped++;
                }
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
            'created' => $created,
            'skipped' => $skipped,
            'message' => "Vendas importadas: {$created} criadas, {$updated} atualizadas, {$skipped} ignoradas (de {$rows} linhas).",
        ];
    }

    /** Converte a situação do Magazord para o status canônico do sistema. */
    private function mapStatus(?string $transporte, ?string $situacao): string
    {
        $s = mb_strtolower(trim(($transporte ?? '') . ' ' . ($situacao ?? '')));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            str_contains($s, 'envi') || str_contains($s, 'transito') || str_contains($s, 'trânsito') || str_contains($s, 'postad') => 'shipped',
            str_contains($s, 'nota fiscal') || str_contains($s, 'faturad') || str_contains($s, 'aprovad') || str_contains($s, 'pago') => 'approved',
            default => 'pending',
        };
    }

    /** Converte "20/07/2026 19:23:36" para datetime. */
    private function parseDate(?string $value): ?string
    {
        if (!$value) return null;
        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value))->toDateTimeString();
            } catch (\Throwable $e) {
                // tenta próximo formato
            }
        }
        return null;
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
