<?php

namespace App\Http\Controllers\Magazord;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Customers\CustomerIdentityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
        'vendas_itens' => [
            'title' => 'Importar Vendas por Item (Consulta Dinâmica)',
            'icon' => 'fa-solid fa-boxes-packing',
            'target' => 'orders + order_items',
            'key_label' => 'Pedido',
            'value_label' => 'SKU · Quantidade · Valor Unitário',
            'description' => 'Modelo "Consulta Dinâmica – Produto por Pedido" (FADERIM → Consultas Dinâmicas). Vem uma linha por ITEM vendido — cria/atualiza o pedido (cruzando por "Pedido") e grava uma linha em order_items por SKU, com quantidade e valor unitário reais. É a única fonte de Vendas que alimenta a velocidade por SKU do motor de Reposição Inteligente — os outros modelos de Vendas gravam só o cabeçalho do pedido.',
            'columns' => ['Pedido', 'Data/Hora', 'Situação', 'Canal', 'SKU', 'Produto', 'Quantidade', 'Valor Unitário', 'Marca', 'Categoria Principal'],
            'can_create' => true,
        ],
        'vendas_detalhes' => [
            'title' => 'Importar Detalhes do Pedido (Consulta Dinâmica)',
            'icon' => 'fa-solid fa-location-dot',
            'target' => 'orders + customers (cidade/estado)',
            'key_label' => 'Pedido',
            'value_label' => 'Cidade · Estado · Vlr Total',
            'description' => 'Modelo "Consulta Dinâmica – Detalhes do Pedido" (FADERIM → Consultas Dinâmicas). Cria/atualiza pedido e cliente com o dado que os outros modelos de Vendas não trazem: cidade e estado do comprador — essencial pro relatório de Vendas por Região, que hoje fica vazio pra pedidos Magazord por falta exatamente desse dado. Cruza pelo "Pedido".',
            'columns' => ['Pedido', 'Data', 'Origem', 'Forma Pgto', 'Situação', 'Pessoa', 'CPF/CNPJ', 'Cidade', 'Estado', 'Vlr Produto', 'Vlr Acréscimo', 'Vlr Desconto', 'Vlr Frete', 'Vlr Total'],
            'can_create' => true,
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
        $this->progressKey = $token ? 'mgz_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
        $this->progTotal = $total;
        $this->progDone = 0;
        $this->writeProgress('processing');
    }

    private function tick(): void
    {
        $this->progDone++;
        if ($this->progressKey && ($this->progDone % 100 === 0)) {
            $this->writeProgress('processing');
        }
    }

    private function writeProgress(string $status, array $extra = []): void
    {
        if (!$this->progressKey) return;
        $this->progressStore()->put($this->progressKey, array_merge([
            'status' => $status,
            'done' => $this->progDone,
            'total' => $this->progTotal,
        ], $extra), now()->addMinutes(15));
    }

    /** Conta as linhas de dados do CSV (rápido) para estimar o total. */
    private function countRows(string $path): int
    {
        $fh = @fopen($path, 'r');
        if (!$fh) return 0;
        $c = 0;
        while (fgets($fh) !== false) $c++;
        fclose($fh);
        return max(0, $c - 1); // desconta o cabeçalho
    }

    /** Endpoint de consulta de progresso (sem sessão, para não travar). */
    public function progress(string $token)
    {
        $key = 'mgz_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

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

        // Arquivos grandes (dezenas de milhares de linhas) podem levar minutos —
        // remove o limite de tempo/abort para a importação concluir e retornar o resumo.
        @set_time_limit(0);
        @ignore_user_abort(true);

        $path = $request->file('file')->getRealPath();
        $createMissing = (bool) $request->boolean('create_missing');

        // Rejeita o .xls do Magazord que na verdade vem como HTML (erro de export).
        $head = @file_get_contents($path, false, null, 0, 64);
        if ($head !== false && stripos($head, '<html') !== false) {
            return redirect()->route('magazord.show', ['type' => $type])
                ->with('error', 'O arquivo enviado é um HTML (provável erro de exportação do Magazord), não uma planilha. Reexporte o relatório como CSV e tente novamente.');
        }

        // Progresso ao vivo: token vindo do frontend + total de linhas do arquivo.
        $this->initProgress((string) $request->input('progress_token', '') ?: null, $this->countRows($path));

        $summary = match ($type) {
            'estoque' => $this->importEstoque($this->readRows($path)),
            'custos' => $this->importCustos($this->readRows($path), $createMissing),
            'precos' => $this->importPrecos($this->readRows($path), $createMissing),
            'descontos' => $this->importDescontos($this->readRows($path)),
            'produtos' => $this->importProdutos($this->readRows($path), $createMissing),
            'vendas' => $this->importVendas($this->readRows($path), $createMissing),
            'vendas_itens' => $this->importVendasItens($this->readRows($path), $createMissing),
            'vendas_detalhes' => $this->importVendasDetalhes($this->readRows($path), $createMissing),
        };

        $this->writeProgress('done', ['result' => $summary]);

        return redirect()->route('magazord.show', ['type' => $type])
            ->with('importResult', $summary)
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
                $rows++; $this->tick();
                $sku = $this->col($row, ['Produto/Derivação Código Der.', 'Código Der.', 'Código']);
                if ($sku === null || $sku === '') continue;
                $qtyRaw = $this->col($row, ['Quantidade Física', 'Quantidade Disp. Venda']);
                $qty = (int) round($this->brNumber($qtyRaw) ?? 0);

                if ($skuToId->has($sku)) {
                    DB::table('products')->where('id', $skuToId[$sku])->update(['stock_quantity' => $qty]);
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
                $rows++; $this->tick();
                $sku = $this->col($row, ['Código']);
                if ($sku === null || $sku === '') { $skipped++; continue; }
                $cost = $this->brNumber($this->col($row, ['Valor Atual']));
                if ($cost === null) { $skipped++; continue; }

                if ($skuToId->has($sku)) {
                    DB::table('products')->where('id', $skuToId[$sku])->update(['cost_price' => $cost]);
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
        $hasChannelPrices = Schema::hasColumn('products', 'channel_prices');
        $hasNetshoesPrice = Schema::hasColumn('products', 'netshoes_price');

        $updated = 0; $created = 0; $notFound = 0; $skipped = 0; $rows = 0; $netshoesLinked = 0;
        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++; $this->tick();
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

                // Custo e estoque (presentes no modelo Consulta Dinâmica). Estoque é
                // único/compartilhado entre canais — não existe "estoque por canal".
                $cost = $this->brNumber($this->col($row, ['Custo']));
                $estoqueRaw = $this->col($row, ['Estoque']);
                $estoque = $estoqueRaw !== null ? (int) round($this->brNumber($estoqueRaw) ?? 0) : null;

                $payload = ['sale_price' => $price];
                if ($cost !== null && $cost > 0) $payload['cost_price'] = $cost;
                if ($estoque !== null) $payload['stock_quantity'] = $estoque;

                // Preços por canal (aproveita 100% do modelo Consulta Dinâmica) — só
                // vincula o canal quando a coluna correspondente vem preenchida (> 0);
                // "0,00" na planilha significa "não vende nesse canal".
                $cp = [];
                foreach ($priceChannels as $ch) {
                    $v = $this->brNumber($this->col($row, [$ch]));
                    if ($v !== null && $v > 0) $cp[$ch] = $v;
                }
                if ($cp && $hasChannelPrices) {
                    $payload['channel_prices'] = json_encode($cp, JSON_UNESCAPED_UNICODE);
                }

                // Canal Netshoes: mesma coluna (netshoes_price) já usada pelo
                // importador dedicado em Importações Netshoes → Preços, para o
                // Monitoramento/Buy Box enxergar o preço sem precisar do export
                // separado do Seller Center.
                if ($hasNetshoesPrice && isset($cp['Netshoes'])) {
                    $payload['netshoes_price'] = $cp['Netshoes'];
                    $payload['netshoes_synced_at'] = now();
                    $netshoesLinked++;
                }

                if ($skuToId->has($sku)) {
                    DB::table('products')->where('id', $skuToId[$sku])->update($this->prune($payload));
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

        $message = "Preços importados: {$updated} atualizados, {$created} criados, {$notFound} SKUs não encontrados (de {$rows} linhas). Preço de venda, custo e estoque atualizados quando presentes no arquivo.";
        $message .= $hasChannelPrices
            ? ' Preço por canal vinculado (Site, Mercado Livre, Amazon, Shopee, Magalu, Centauro, Dafiti, Via Varejo).'
            : ' ATENÇÃO: a coluna channel_prices não existe no banco — o preço por canal não foi salvo (rode as migrations pendentes).';
        if ($hasNetshoesPrice) {
            $message .= " Netshoes: {$netshoesLinked} produtos sincronizados com o preço do canal dedicado (netshoes_price).";
        }

        return [
            'ok' => true,
            'rows' => $rows,
            'updated' => $updated,
            'created' => $created,
            'skipped' => $notFound + $skipped,
            'message' => $message,
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
                $rows++; $this->tick();
                $sku = $this->col($row, ['Produto', 'Código']);
                if ($sku === null || $sku === '') { $skipped++; continue; }

                $de = $this->brNumber($this->col($row, ['Preço Antigo']));       // preço cheio
                $por = $this->brNumber($this->col($row, ['Preço Venda', 'Preço'])); // preço praticado

                $payload = [];
                if ($de !== null && $de > 0) $payload['sale_price'] = $de;
                if ($por !== null && $por > 0) $payload['promotional_price'] = $por;
                if (empty($payload)) { $skipped++; continue; }

                if ($skuToId->has($sku)) {
                    DB::table('products')->where('id', $skuToId[$sku])->update($this->prune($payload));
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
                $rows++; $this->tick();
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
                        DB::table('products')->where('id', $skuToId[$sku])->update($safe);
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
        $hasCustomerId = in_array('customer_id', $cols, true);
        $customerIdentity = $hasCustomerId ? app(CustomerIdentityService::class) : null;

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++; $this->tick();
                $externalId = $this->col($row, ['Pedido Id']);
                if ($externalId === null || $externalId === '') { $skipped++; continue; }

                $total = $this->brNumber($this->col($row, ['Valor Total Pedido', 'Valor Total'])) ?? 0;
                $docRaw = $this->col($row, ['CPF/CNPJ']);
                $nameRaw = $this->col($row, ['Cliente']);
                $emailRaw = $this->col($row, ['E-mail']);
                $channelRaw = $this->col($row, ['Marketplace']) ?: $this->col($row, ['Loja']);

                $payload = [$keyCol => $externalId];
                if ($nameCol)   $payload[$nameCol]   = $nameRaw;
                if ($emailCol)  $payload[$emailCol]  = $emailRaw;
                if ($docCol)    $payload[$docCol]    = $docRaw;
                if ($channelCol) $payload[$channelCol] = $channelRaw;
                if ($payCol)    $payload[$payCol]    = $this->col($row, ['Forma de Pagamento']);
                if ($statusCol) $payload[$statusCol] = $this->mapStatus($this->col($row, ['Situação - Transporte']), $this->col($row, ['Situação']));
                if ($totalCol)  $payload[$totalCol]  = $total;
                if ($paidCol)   $payload[$paidCol]   = $total;
                if ($dateCol)   $payload[$dateCol]   = $this->parseDate($this->col($row, ['Data/Hora', 'Data Aprovação']));

                // CPF é a chave que une o mesmo cliente entre canais — ver
                // CustomerIdentityService. Sem isso, cada importação criava um
                // pedido "solto", sem nenhum vínculo com o histórico do cliente.
                if ($customerIdentity) {
                    $customer = $customerIdentity->findOrCreate($companyId, [
                        'doc_number' => $docRaw, 'name' => $nameRaw, 'email' => $emailRaw, 'origin_channel' => $channelRaw,
                    ]);
                    if ($customer) {
                        $payload['customer_id'] = $customer->id;
                    }
                }

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

    /**
     * DETALHES DO PEDIDO (Consulta Dinâmica "Detalhes do Pedido", FADERIM) ->
     * orders + customers.
     *
     * É a única fonte de Vendas que traz cidade e estado do comprador — os
     * outros importadores (Magazord "vendas", "vendas_itens", Netshoes)
     * nunca capturam essa informação, então o relatório de Vendas por Região
     * (Central de Vendas) ficava vazio pra praticamente todo o catálogo de
     * pedidos. Vem uma linha por PEDIDO (não por item). Alimenta o CPF via
     * CustomerIdentityService, igual aos outros dois importadores de Vendas.
     */
    private function importVendasDetalhes(iterable $records, bool $createMissing): array
    {
        $companyId = Auth::user()->company_id;
        $updated = 0; $created = 0; $skipped = 0; $rows = 0;

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
        $shippingCol = $pick(['shipping_cost']);
        $dateCol = $pick(['date_created', 'order_date']);
        $hasCompany = in_array('company_id', $cols, true);
        $hasTimestamps = in_array('created_at', $cols, true);
        $hasCustomerId = in_array('customer_id', $cols, true);
        $customerIdentity = $hasCustomerId ? app(CustomerIdentityService::class) : null;

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        DB::beginTransaction();
        try {
            foreach ($records as $row) {
                $rows++; $this->tick();
                $externalId = $this->col($row, ['Pedido']);
                if ($externalId === null || $externalId === '') { $skipped++; continue; }

                $total = $this->brNumber($this->col($row, ['Vlr Total'])) ?? 0;
                $frete = $this->brNumber($this->col($row, ['Vlr Frete']));
                $docRaw = $this->col($row, ['CPF/CNPJ']);
                $nameRaw = $this->col($row, ['Pessoa']);
                $channelRaw = $this->col($row, ['Origem']);
                $cityRaw = $this->col($row, ['Cidade']);
                $stateRaw = $this->col($row, ['Estado']);

                $payload = [$keyCol => $externalId];
                if ($nameCol)     $payload[$nameCol]     = $nameRaw;
                if ($docCol)      $payload[$docCol]      = $docRaw;
                if ($channelCol)  $payload[$channelCol]  = $channelRaw;
                if ($payCol)      $payload[$payCol]      = $this->col($row, ['Forma Pgto']);
                if ($statusCol)   $payload[$statusCol]   = $this->mapStatus(null, $this->col($row, ['Situação']));
                if ($totalCol)    $payload[$totalCol]    = $total;
                if ($paidCol)     $payload[$paidCol]     = $total;
                if ($shippingCol && $frete !== null) $payload[$shippingCol] = $frete;
                if ($dateCol)     $payload[$dateCol]     = $this->parseDate($this->col($row, ['Data']));

                // CPF + cidade/estado é a chave que une o mesmo cliente entre
                // canais E alimenta a região — ver CustomerIdentityService.
                if ($customerIdentity) {
                    $customer = $customerIdentity->findOrCreate($companyId, [
                        'doc_number' => $docRaw, 'name' => $nameRaw, 'city' => $cityRaw, 'state' => $stateRaw, 'origin_channel' => $channelRaw,
                    ]);
                    if ($customer) {
                        $payload['customer_id'] = $customer->id;
                    }
                }

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
            'message' => "Detalhes de pedido importados: {$created} criados, {$updated} atualizados, {$skipped} ignorados (de {$rows} linhas).",
        ];
    }

    /**
     * VENDAS POR ITEM (Consulta Dinâmica "Produto por Pedido", FADERIM) ->
     * orders + order_items.
     *
     * É a única fonte de Vendas do sistema que traz SKU + quantidade + valor
     * unitário por linha — os outros dois importadores de Vendas (Magazord
     * "vendas" e Netshoes "vendas") gravam só o cabeçalho do pedido, então
     * `order_items` nunca era escrita e o motor de Reposição Inteligente via
     * velocity=0 em 100% do catálogo (ver ReplenishmentEngine).
     *
     * Vem uma linha por item — agrupa em memória por "Pedido" antes de
     * gravar (mesmo padrão do importador de Vendas Netshoes). Um mesmo SKU
     * pode aparecer mais de uma vez no mesmo pedido no arquivo real; soma a
     * quantidade em vez de duplicar a linha, pra reimportação ficar idempotente.
     */
    private function importVendasItens(iterable $records, bool $createMissing): array
    {
        $companyId = Auth::user()->company_id;

        $skuToProduct = Product::where('company_id', $companyId)
            ->whereNotNull('sku')
            ->get(['id', 'sku', 'cost_price'])
            ->keyBy('sku');

        $orderCols = Schema::getColumnListing('orders');
        $pick = fn (array $cands) => collect($cands)->first(fn ($c) => in_array($c, $orderCols, true));
        $keyCol = $pick(['external_id', 'ml_order_id']);
        $channelCol = $pick(['selling_channel']);
        $statusCol = $pick(['status']);
        $totalCol = $pick(['total_amount']);
        $paidCol = $pick(['total_paid_amount']);
        $dateCol = $pick(['date_created', 'order_date']);
        $hasCompany = in_array('company_id', $orderCols, true);
        $hasTimestamps = in_array('created_at', $orderCols, true);

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        $porPedido = [];
        $rows = 0;
        try {
            foreach ($records as $row) {
                $rows++; $this->tick();

                $pedido = $this->col($row, ['Pedido']);
                if ($pedido === null || $pedido === '') continue;

                if (!isset($porPedido[$pedido])) {
                    $porPedido[$pedido] = ['header' => $row, 'items' => []];
                }

                $sku = $this->col($row, ['SKU']);
                if ($sku === null || $sku === '') continue;

                $qty = (int) round($this->brNumber($this->col($row, ['Quantidade'])) ?? 0);
                $unitPrice = $this->brNumber($this->col($row, ['Valor Unitário'])) ?? 0.0;

                if (!isset($porPedido[$pedido]['items'][$sku])) {
                    $porPedido[$pedido]['items'][$sku] = [
                        'sku' => $sku,
                        'title' => $this->col($row, ['Produto']),
                        'quantity' => 0,
                        'unit_price' => $unitPrice,
                    ];
                }
                $porPedido[$pedido]['items'][$sku]['quantity'] += $qty;
            }
        } catch (\Throwable $e) {
            // Nada foi gravado ainda (leitura/agrupamento) — sem rollback a fazer.
            return $this->fail($e);
        }

        $ordersCreated = 0; $ordersUpdated = 0; $ordersSkipped = 0; $itemsWritten = 0; $skusNotFound = 0;

        DB::beginTransaction();
        try {
            foreach ($porPedido as $pedido => $group) {
                $header = $group['header'];

                $payload = [$keyCol => $pedido];
                if ($channelCol) $payload[$channelCol] = $this->col($header, ['Canal']) ?: null;
                if ($statusCol) $payload[$statusCol] = $this->mapStatus(null, $this->col($header, ['Situação']));
                if ($dateCol) $payload[$dateCol] = $this->parseDate($this->col($header, ['Data/Hora']));

                $query = DB::table('orders')->where($keyCol, $pedido);
                if ($hasCompany) $query->where('company_id', $companyId);
                $existing = $query->first();

                if ($existing) {
                    if ($hasTimestamps) $payload['updated_at'] = now();
                    (clone $query)->update($payload);
                    $orderId = $existing->id;
                    $ordersUpdated++;
                } elseif ($createMissing) {
                    $total = 0.0;
                    foreach ($group['items'] as $item) {
                        $total += $item['quantity'] * $item['unit_price'];
                    }
                    if ($totalCol) $payload[$totalCol] = round($total, 2);
                    if ($paidCol) $payload[$paidCol] = round($total, 2);
                    if ($hasCompany) $payload['company_id'] = $companyId;
                    if ($hasTimestamps) { $payload['created_at'] = now(); $payload['updated_at'] = now(); }
                    $orderId = DB::table('orders')->insertGetId($payload);
                    $ordersCreated++;
                } else {
                    $ordersSkipped++;
                    continue;
                }

                foreach ($group['items'] as $item) {
                    $product = $skuToProduct->get($item['sku']);
                    if (!$product) $skusNotFound++;

                    // Custo real do produto quando conhecido; senão estimativa de 50% do
                    // preço de venda (mesmo fallback já usado em SyncOrdersCommand::saveOrders()
                    // pra pedidos ML sem produto cadastrado — não é precisão inventada, é o
                    // mesmo critério já em produção).
                    $unitCost = ($product && (float) $product->cost_price > 0)
                        ? (float) $product->cost_price
                        : $item['unit_price'] * 0.5;

                    $itemPayload = [
                        'product_id' => $product?->id,
                        'title' => $item['title'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'unit_cost' => $unitCost,
                    ];

                    $itemQuery = DB::table('order_items')->where('order_id', $orderId)->where('sku', $item['sku']);
                    $existingItem = $itemQuery->first();
                    if ($existingItem) {
                        $itemPayload['updated_at'] = now();
                        (clone $itemQuery)->update($itemPayload);
                    } else {
                        $itemPayload['order_id'] = $orderId;
                        $itemPayload['sku'] = $item['sku'];
                        $itemPayload['created_at'] = now();
                        $itemPayload['updated_at'] = now();
                        DB::table('order_items')->insert($itemPayload);
                    }
                    $itemsWritten++;
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
            'updated' => $ordersUpdated,
            'created' => $ordersCreated,
            'skipped' => $ordersSkipped,
            'message' => "Vendas por item: {$ordersCreated} pedidos criados, {$ordersUpdated} atualizados, {$ordersSkipped} ignorados "
                . "(" . count($porPedido) . " pedidos únicos, {$itemsWritten} itens gravados, {$skusNotFound} SKUs não encontrados no catálogo, de {$rows} linhas).",
        ];
    }

    /**
     * Converte a situação do Magazord para o status canônico do sistema.
     *
     * Incidente: o valor "Transporte" (pacote já despachado, com a transportadora)
     * não batia em nenhum `str_contains` — só "transito"/"trânsito"/"envi"/"postad"
     * eram reconhecidos como shipped. Caía no fallback "pending", que fica FORA de
     * Order::CONFIRMED_STATUSES. Confirmado contra um export real (Consulta
     * Dinâmica "Produto por Pedido"): "Transporte" era 27% de todas as linhas do
     * arquivo — a maior fatia de qualquer status — então esse gap silenciava uma
     * parte grande das vendas confirmadas em toda tela que depende de
     * CONFIRMED_STATUSES (mesma classe de bug do PR #19).
     */
    private function mapStatus(?string $transporte, ?string $situacao): string
    {
        $s = mb_strtolower(trim(($transporte ?? '') . ' ' . ($situacao ?? '')));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            str_contains($s, 'envi') || str_contains($s, 'transito') || str_contains($s, 'trânsito') || str_contains($s, 'transporte') || str_contains($s, 'postad') => 'shipped',
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
