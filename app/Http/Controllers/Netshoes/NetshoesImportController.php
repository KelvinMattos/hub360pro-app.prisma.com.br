<?php

namespace App\Http\Controllers\Netshoes;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Services\Customers\CustomerIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importações Netshoes (só canal — NÃO altera o catálogo, exceto Vendas).
 *
 * Recebe os exports .xlsx do painel Netshoes / Seller Center e grava a
 * sobreposição de canal nos produtos já existentes, cruzando pelo `sku`
 * (igual ao "ID Sku" / "Sku Seller" da Netshoes):
 *   - Produtos (export "Portal")  -> netshoes_sku, netshoes_price(_from), netshoes_status
 *   - Estoque  (export "INVENTORY") -> netshoes_stock
 *   - Preços   (export "PRICE")     -> netshoes_price, netshoes_price_from
 *   - Vendas   (export de Pedidos do Seller Center, aba "pedidos_por_item")
 *     -> cria/atualiza `orders` (única exceção que grava fora de `products`;
 *        o arquivo vem por ITEM do pedido, não por pedido — agrupamos por
 *        "Número Pedido" antes de gravar).
 *
 * O parsing de produtos/estoque/preços é 100% por streaming (openspout) para
 * aguentar dezenas de milhares de linhas sem estourar memória. Vendas é lido
 * inteiro em memória (arquivos de pedidos são ordens de grandeza menores).
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
            'kind' => 'products',
        ],
        'estoque' => [
            'title' => 'Importar Estoque Netshoes',
            'icon' => 'fa-solid fa-boxes-stacked',
            'target' => 'products.netshoes_stock',
            'key_label' => 'Sku Seller  →  sku do produto',
            'value_label' => 'Quantidade disponível',
            'description' => 'Export "INVENTORY" da Netshoes (.xlsx). Cruza pelo SKU interno (coluna "Sku Seller" = sku do produto) e atualiza o estoque disponível no canal Netshoes.',
            'columns' => ['Sku Seller', 'Quantidade disponível'],
            'kind' => 'products',
        ],
        'precos' => [
            'title' => 'Importar Preços Netshoes',
            'icon' => 'fa-solid fa-tag',
            'target' => 'products (netshoes_price, netshoes_price_from)',
            'key_label' => 'Sku Seller  →  sku do produto',
            'value_label' => 'Preço De · Preço Por',
            'description' => 'Export "PRICE" da Netshoes (.xlsx). Cruza pelo SKU interno (coluna "Sku Seller" = sku do produto) e atualiza o preço De/Por praticado no canal Netshoes. Não cria produtos nem altera o catálogo.',
            'columns' => ['Sku Seller', 'Preço De', 'Preço Por'],
            'kind' => 'products',
        ],
        'vendas' => [
            'title' => 'Importar Vendas Netshoes',
            'icon' => 'fa-solid fa-cart-shopping',
            'target' => 'orders',
            'key_label' => 'Número Pedido  →  identificador do pedido',
            'value_label' => 'Cliente, status, data, valor total',
            'description' => 'Export de Pedidos do Seller Center (.xlsx, aba "pedidos_por_item"). O arquivo vem por ITEM do pedido — este importador agrupa por "Número Pedido" antes de gravar. Cria/atualiza pedidos (não cria itens de produto). Pedidos do tipo "Troca" são ignorados.',
            'columns' => ['Número Pedido', 'Tipo do Pedido', 'Status do Pedido', 'Data da Compra', 'Valor Total Pedido Lojista', 'Nome do Comprador', 'CPF/CNPJ do Comprador', 'Forma de pagamento', 'Site Origem da Venda'],
            'kind' => 'orders',
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
            'precos' => $this->importPrecos($path),
            'vendas' => $this->importVendas($path),
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
     *  PREÇOS (export "PRICE") -> netshoes_price / netshoes_price_from
     * ============================================================ */
    private function importPrecos(string $path): array
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

                $payload = $this->prune([
                    'netshoes_price' => $this->num($this->col($row, ['Preço Por', 'Preco Por'])),
                    'netshoes_price_from' => $this->num($this->col($row, ['Preço De', 'Preco De'])),
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
            'message' => "Preços Netshoes: {$updated} atualizados, {$notFound} SKUs não encontrados no catálogo (de {$rows} linhas).",
        ];
    }

    /* ============================================================
     *  VENDAS (export de Pedidos do Seller Center, "pedidos_por_item")
     *  -> orders (cria/atualiza; único tipo que grava fora de products)
     * ============================================================ */
    private function importVendas(string $path): array
    {
        $companyId = Auth::user()->company_id;

        $cols = Schema::getColumnListing('orders');
        $pick = fn (array $cands) => collect($cands)->first(fn ($c) => in_array($c, $cols, true));
        $keyCol = $pick(['external_id', 'ml_order_id']);
        $nameCol = $pick(['customer_name', 'buyer_nickname']);
        $docCol = $pick(['customer_doc', 'billing_doc_number']);
        $channelCol = $pick(['selling_channel']);
        $payCol = $pick(['payment_method', 'payment_status']);
        $statusCol = $pick(['status']);
        $totalCol = $pick(['total_amount']);
        $paidCol = $pick(['total_paid_amount']);
        $dateCol = $pick(['date_created', 'order_date']);
        $hasCompany = in_array('company_id', $cols, true);
        $hasTimestamps = in_array('created_at', $cols, true);
        $hasCustomerId = in_array('customer_id', $cols, true);
        $customerIdentity = $hasCustomerId ? app(CustomerIdentityService::class) : null;

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        // Arquivo vem por ITEM do pedido (várias linhas por "Número Pedido",
        // com os campos de pedido idênticos entre elas) — agrupa em memória
        // antes de gravar. Volume esperado é o de um período de vendas, não
        // o catálogo inteiro, então não precisa de streaming/lote.
        $porPedido = [];
        $rows = 0; $trocas = 0;
        try {
            foreach ($this->readRows($path) as $row) {
                $rows++; $this->tick();

                $numero = $this->col($row, ['Número Pedido', 'Numero Pedido']);
                if ($numero === null || $numero === '') continue;

                $tipo = $this->col($row, ['Tipo do Pedido']);
                if ($tipo !== null && mb_strtolower(trim($tipo)) === 'troca') {
                    $trocas++;
                    continue;
                }

                if (!isset($porPedido[$numero])) {
                    $porPedido[$numero] = $row;
                }
            }
        } catch (\Throwable $e) {
            // Nada foi gravado ainda (leitura do arquivo) — sem rollback a fazer.
            return $this->fail($e);
        }

        // Pré-carrega em LOTE quem já existe (pedidos e clientes) em vez de 1
        // SELECT por pedido dentro do loop de escrita — era o gargalo real de
        // um arquivo de poucos milhares de pedidos estourar o timeout de
        // ~100s do Cloudflare (incidente relatado 01/08/2026, Error 524):
        // ~3-5 queries sequenciais por pedido único (SELECT pedido + SELECT/
        // UPDATE/INSERT cliente + INSERT/UPDATE pedido), sem nenhuma delas
        // em lote. Agora é O(poucas dezenas de queries), não O(pedidos).
        $numeros = array_keys($porPedido);
        $existingOrderIds = collect();
        foreach (array_chunk($numeros, 1000) as $chunk) {
            $q = DB::table('orders')->whereIn($keyCol, $chunk);
            if ($hasCompany) $q->where('company_id', $companyId);
            $existingOrderIds = $existingOrderIds->merge($q->pluck($keyCol));
        }
        $existingOrderIds = $existingOrderIds->flip();

        $existingCustomersByDoc = collect();
        if ($customerIdentity) {
            $docs = collect($porPedido)
                ->map(fn ($row) => CustomerIdentityService::normalizeDoc($this->col($row, ['CPF/CNPJ do Comprador'])))
                ->filter()
                ->unique()
                ->values()
                ->all();
            foreach (array_chunk($docs, 1000) as $chunk) {
                $existingCustomersByDoc = $existingCustomersByDoc->merge(
                    Customer::where('company_id', $companyId)->whereIn('doc_number', $chunk)->get()->keyBy('doc_number')
                );
            }
        }

        $created = 0; $updated = 0; $skipped = 0;
        $now = now();
        $upsertRows = [];

        DB::beginTransaction();
        try {
            foreach ($porPedido as $numero => $row) {
                $total = $this->num($this->col($row, ['Valor Total Pedido Lojista', 'Valor Total Pedido']));
                if ($total === null) { $skipped++; continue; }

                $marketplace = $this->col($row, ['Site Origem da Venda']);
                $docRaw = $this->col($row, ['CPF/CNPJ do Comprador']);
                $nameRaw = $this->col($row, ['Nome do Comprador']);
                $channelRaw = $marketplace ? ucwords(mb_strtolower($marketplace)) : 'Netshoes';

                $payload = [$keyCol => $numero];
                if ($nameCol) $payload[$nameCol] = $nameRaw;
                if ($docCol) $payload[$docCol] = $docRaw;
                if ($channelCol) $payload[$channelCol] = $channelRaw;
                if ($payCol) $payload[$payCol] = $this->col($row, ['Forma de pagamento']);
                if ($statusCol) $payload[$statusCol] = $this->mapStatus($this->col($row, ['Status do Pedido']));
                if ($totalCol) $payload[$totalCol] = $total;
                if ($paidCol) $payload[$paidCol] = $total;
                if ($dateCol) $payload[$dateCol] = $this->parseDate($this->col($row, ['Data da Compra']));

                // CPF é a chave que une o mesmo cliente entre canais — ver
                // CustomerIdentityService. Resolvido em lote acima quando o
                // cliente já existe; só cai no findOrCreate individual (mais
                // caro) pra cliente novo ou sem CPF — a minoria do lote.
                if ($customerIdentity) {
                    $doc = CustomerIdentityService::normalizeDoc($docRaw);
                    $customer = $doc ? ($existingCustomersByDoc[$doc] ?? null) : null;
                    if (!$customer) {
                        $customer = $customerIdentity->findOrCreate($companyId, [
                            'doc_number' => $docRaw, 'name' => $nameRaw, 'origin_channel' => $channelRaw,
                        ]);
                        if ($customer && $doc) {
                            $existingCustomersByDoc[$doc] = $customer; // não recria se o CPF repetir no mesmo lote
                        }
                    }
                    if ($customer) {
                        $payload['customer_id'] = $customer->id;
                    }
                }

                if ($hasCompany) $payload['company_id'] = $companyId;
                if ($hasTimestamps) { $payload['created_at'] = $now; $payload['updated_at'] = $now; }

                isset($existingOrderIds[$numero]) ? $updated++ : $created++;
                $upsertRows[] = $payload;
            }

            if (!empty($upsertRows)) {
                $updateCols = array_values(array_filter([
                    $nameCol, $docCol, $channelCol, $payCol, $statusCol, $totalCol, $paidCol, $dateCol,
                    $hasCustomerId ? 'customer_id' : null,
                    $hasTimestamps ? 'updated_at' : null,
                ]));
                // upsert() exige colunas iguais em todas as linhas do lote — já
                // garantido acima, os mesmos $xCol valem pro arquivo inteiro.
                foreach (array_chunk($upsertRows, 500) as $chunk) {
                    DB::table('orders')->upsert($chunk, [$keyCol], $updateCols);
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
            'message' => "Vendas Netshoes: {$created} criados, {$updated} atualizados, {$skipped} ignorados"
                . ($trocas > 0 ? ", {$trocas} trocas ignoradas" : '')
                . " (de {$rows} linhas de item, " . count($porPedido) . " pedidos únicos).",
        ];
    }

    /** Converte a situação do pedido Netshoes para o status canônico do sistema. */
    private function mapStatus(?string $situacao): string
    {
        $s = mb_strtolower(trim((string) $situacao));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            str_contains($s, 'envi') || str_contains($s, 'transito') || str_contains($s, 'trânsito') || str_contains($s, 'postad') => 'shipped',
            str_contains($s, 'faturad') || str_contains($s, 'aprovad') || str_contains($s, 'pago') => 'approved',
            default => 'pending',
        };
    }

    /** Datas do export de Pedidos vêm como "DD/MM/AAAA HH:MM:SS". */
    private function parseDate(?string $value): ?string
    {
        if (!$value) return null;
        foreach (['!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, trim($value))->toDateTimeString();
            } catch (\Throwable $e) {
                // tenta o próximo formato
            }
        }
        return null;
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
