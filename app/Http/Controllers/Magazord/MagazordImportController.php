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
            'target' => 'products.sale_price + cost_price + stock_quantity',
            'key_label' => 'Código',
            'value_label' => 'Site (preço base) · Custo · Estoque',
            'description' => 'Modelo "Consulta Dinâmica – Custo x Preço de Venda": atualiza o preço de venda (base = coluna "Site", ou o maior preço entre os canais), o custo e o estoque de uma vez, cruzando pelo Código. Também aceita um modelo simples com Produto Código / Preço Venda.',
            'columns' => ['Código', 'Produto', 'Marca', 'Custo', 'Estoque', 'Site', 'Shopee', 'Mercado Livre', 'Centauro', 'Via Varejo', 'Magalu', 'Dafiti', 'Amazon', 'Netshoes', 'Ativo'],
            'can_create' => true,
        ],
        'descontos' => [
            'title' => 'Importar Produtos com Desconto',
            'icon' => 'fa-solid fa-percent',
            'target' => 'products.sale_price (De) + promotional_price (Por)',
            'key_label' => 'Produto',
            'value_label' => 'Preço Antigo → venda · Preço Venda → promocional',
            'description' => 'Modelo "Consulta Dinâmica – Produtos com Desconto". Grava o preço cheio (Preço Antigo/De) em sale_price e o preço praticado (Preço Venda/Por) em promotional_price, cruzando pelo Produto (SKU). Só lista produtos que têm desconto ativo.',
            'columns' => ['Loja', 'ID Produto', 'Produto', 'Preço Antigo', 'Preço Venda', 'Desconto %', 'Ativo'],
            'can_create' => false,
        ],
        'produtos' => [
            'title' => 'Importar Produtos & Datas',
            'icon' => 'fa-solid fa-calendar-day',
            'target' => 'products (launched_at, catalog_updated_at, EAN, dimensões)',
            'key_label' => 'Código',
            'value_label' => 'Data de Lançamento · Data Atualização',
            'description' => 'Modelo "Consulta de Derivação do Produto": importa a Data de Lançamento e a Data de Atualização (para calcular o tempo de estoque), além de EAN, marca e dimensões. Cruza pelo Código. Registros "Pai" só são criados como produto se "criar inexistentes" estiver marcado — o padrão cria apenas variações ("Filho").',
            'columns' => ['Código', 'Produto - Derivação', 'Marca', 'Qtde Estoque', 'EAN', 'Peso (kg)', 'Largura (cm)', 'Altura (cm)', 'Comprimento (cm)', 'Data de Lançamento', 'Data Atualização Produto', 'Ativo'],
            'can_create' => true,
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
            'precos' => $this->importPrecos($this->readRows($path), $createMissing),
            'descontos' => $this->importDescontos($this->readRows($path)),
            'produtos' => $this->importProdutos($this->readRows($path), $createMissing),
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

    /**
     * Remove do payload as chaves que não existem na tabela products.
     * Evita que um campo ausente (brand, dimensões, etc.) derrube o import
     * inteiro com "Unknown column".
     */
    private function prune(array $payload): array
    {
        $cols = $this->productColumns();
        return empty($cols) ? $payload : array_intersect_key($payload, array_flip($cols));
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
     *  PREÇOS DE VENDA -> products.sale_price (+ custo/estoque)
     *
     *  Aceita dois modelos:
     *   - "Consulta Dinâmica – Custo x Preço de Venda" (PV ATUAL): tem
     *     Código, Custo, Estoque e o preço por canal (Site, Shopee, ...).
     *     Nesse caso atualiza sale_price (base = Site), cost_price e
     *     stock_quantity de uma vez — preenche os dados que faltam.
     *   - Modelo simples (Produto Código / Preço Venda): só sale_price.
     * ============================================================ */
    private function importPrecos(iterable $records, bool $createMissing = false): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $priceChannels = ['Site', 'Mercado Livre', 'Amazon', 'Netshoes', 'Shopee', 'Magalu', 'Centauro', 'Dafiti', 'Via Varejo'];

        $updated = 0; $created = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Produto Código', 'Código', 'Código Produto']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                // Preço base: "Site" quando > 0; senão o maior preço entre os canais.
                $sitePrice = $this->brNumber($this->col($row, ['Site']));
                $simplePrice = $this->brNumber($this->col($row, ['Preço Venda', 'Preço', 'Preço Por']));
                $price = $sitePrice !== null ? $sitePrice : $simplePrice;
                if ($price === null || $price <= 0) {
                    $max = 0.0;
                    foreach ($priceChannels as $ch) {
                        $v = $this->brNumber($this->col($row, [$ch]));
                        if ($v !== null && $v > $max) $max = $v;
                    }
                    $price = $max;
                }
                if ($price <= 0) { $skipped++; continue; }

                // Custo e estoque (presentes no modelo Consulta Dinâmica).
                $cost = $this->brNumber($this->col($row, ['Custo']));
                $estoqueRaw = $this->col($row, ['Estoque']);
                $estoque = $estoqueRaw !== null ? (int) round($this->brNumber($estoqueRaw) ?? 0) : null;

                $payload = ['sale_price' => $price];
                if ($cost !== null && $cost > 0) $payload['cost_price'] = $cost;
                if ($estoque !== null) $payload['stock_quantity'] = $estoque;

                // Preços por canal (aproveita 100% do modelo Consulta Dinâmica).
                $cp = [];
                foreach ($priceChannels as $ch) {
                    $v = $this->brNumber($this->col($row, [$ch]));
                    if ($v !== null && $v > 0) $cp[$ch] = $v;
                }
                if ($cp && Schema::hasColumn('products', 'channel_prices')) {
                    $payload['channel_prices'] = json_encode($cp, JSON_UNESCAPED_UNICODE);
                }

                if ($skuToId->has($sku)) {
                    Product::where('id', $skuToId[$sku])->update($this->prune($payload));
                    $updated++;
                } elseif ($createMissing) {
                    $ativo = mb_strtolower((string) $this->col($row, ['Ativo', 'Produto Ativo'])) !== 'não'
                        && mb_strtolower((string) $this->col($row, ['Ativo', 'Produto Ativo'])) !== 'nao';
                    $p = Product::create($this->prune(array_merge($payload, [
                        'company_id' => $companyId,
                        'sku' => $sku,
                        'title' => $this->col($row, ['Produto', 'Produto Nome']) ?: $sku,
                        'brand' => $this->col($row, ['Marca']),
                        'status' => $ativo ? 'active' : 'inactive',
                    ])));
                    $skuToId[$sku] = $p->id;
                    $created++;
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
            'created' => $created,
            'skipped' => $notFound + $skipped,
            'message' => "Preços importados: {$updated} atualizados, {$created} criados, {$notFound} SKUs não encontrados (de {$rows} linhas). Preço de venda, custo e estoque atualizados quando presentes no arquivo.",
        ];
    }

    /* ============================================================
     *  PRODUTOS COM DESCONTO -> products.sale_price (De) + promotional_price (Por)
     *
     *  Modelo "Consulta Dinâmica – Produtos com Desconto". Preço Antigo é o
     *  preço cheio (De) e Preço Venda é o preço praticado (Por).
     * ============================================================ */
    private function importDescontos(iterable $records): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Produto', 'Código']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                $de = $this->brNumber($this->col($row, ['Preço Antigo']));       // preço cheio
                $por = $this->brNumber($this->col($row, ['Preço Venda', 'Preço'])); // preço praticado

                $payload = [];
                if ($de !== null && $de > 0) $payload['sale_price'] = $de;
                if ($por !== null && $por > 0) $payload['promotional_price'] = $por;
                if (empty($payload)) { $skipped++; continue; }

                if ($skuToId->has($sku)) {
                    Product::where('id', $skuToId[$sku])->update($this->prune($payload));
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
            'message' => "Produtos com desconto importados: {$updated} atualizados, {$notFound} SKUs não encontrados (de {$rows} linhas). Preço cheio → venda; preço praticado → promocional.",
        ];
    }

    /* ============================================================
     *  PRODUTOS & DATAS -> products (launched_at, catalog_updated_at, ...)
     *
     *  Modelo "Consulta de Derivação do Produto". Grava a Data de Lançamento
     *  e a Data de Atualização (base do cálculo de tempo de estoque) e
     *  enriquece EAN, marca e dimensões. Cruza pelo Código.
     * ============================================================ */
    private function importProdutos(iterable $records, bool $createMissing): array
    {
        $companyId = Auth::user()->company_id;
        $skuToId = Product::where('company_id', $companyId)
            ->whereNotNull('sku')->pluck('id', 'sku');

        $updated = 0; $created = 0; $notFound = 0; $skipped = 0; $rows = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++;
                $sku = $this->col($row, ['Código']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                $launched = $this->parseDate($this->col($row, ['Data de Lançamento']));
                $catalogUpdated = $this->parseDate($this->col($row, ['Data Atualização Produto']));

                $payload = [];
                if ($launched !== null) $payload['launched_at'] = $launched;
                if ($catalogUpdated !== null) $payload['catalog_updated_at'] = $catalogUpdated;
                $ean = $this->col($row, ['EAN']);
                if ($ean) $payload['ean'] = $ean;
                $brand = $this->col($row, ['Marca']);
                if ($brand) $payload['brand'] = $brand;
                foreach ([['weight', 'Peso (kg)'], ['width', 'Largura (cm)'], ['height', 'Altura (cm)'], ['length', 'Comprimento (cm)']] as [$field, $header]) {
                    $v = $this->brNumber($this->col($row, [$header]));
                    if ($v !== null && $v > 0) $payload[$field] = $v;
                }

                if ($skuToId->has($sku)) {
                    $safe = $this->prune($payload);
                    if (!empty($safe)) {
                        Product::where('id', $skuToId[$sku])->update($safe);
                    }
                    $updated++;
                } elseif ($createMissing && mb_strtolower((string) $this->col($row, ['Tipo Registro'])) !== 'pai') {
                    // cria apenas variações ("Filho"), evitando os códigos "Pai"/OLD_*
                    $ativo = mb_strtolower((string) $this->col($row, ['Ativo'])) === 'sim';
                    $p = Product::create($this->prune(array_merge($payload, [
                        'company_id' => $companyId,
                        'sku' => $sku,
                        'title' => $this->col($row, ['Produto - Derivação', 'Nome da Derivação']) ?: $sku,
                        'status' => $ativo ? 'active' : 'inactive',
                    ])));
                    $skuToId[$sku] = $p->id;
                    $created++;
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
            'created' => $created,
            'skipped' => $notFound + $skipped,
            'message' => "Produtos & datas importados: {$updated} atualizados, {$created} criados, {$notFound} SKUs não encontrados (de {$rows} linhas). Data de lançamento gravada para calcular o tempo de estoque.",
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
        // O prefixo "!" zera os campos não informados (datas sem hora viram 00:00:00
        // em vez de herdarem a hora atual).
        foreach (['!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $fmt) {
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
