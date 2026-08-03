<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\SalesChannelAccount;
use App\Services\Customers\CustomerIdentityService;
use App\Support\OrderImportChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Importadores nativos de Vendas por canal — recebem o export próprio de
 * cada marketplace (Mercado Livre, Shopee, Centauro, Renner, Magazine
 * Luiza) e criam/atualizam `orders` (+ `order_items` quando o arquivo traz
 * item/SKU). Mesmo desenho de NetshoesImportController/MagazordImportController
 * (TYPES + show/import/progress + um importXxx() privado por canal), com
 * uma diferença: cada canal aqui suporta MÚLTIPLAS CONTAS (pedido do
 * cliente 03/08/2026 — "posso ter duas contas do Mercado Livre, 3 da
 * Shopee") via `sales_channel_accounts` — o upload exige escolher a conta.
 *
 * Cada formato foi validado linha a linha contra o arquivo real enviado
 * pelo cliente antes de escrever o parser (CLAUDE.md §2.4). Achados que
 * moldaram o desenho:
 *  - Mercado Livre: "Total (BRL)" só vem preenchido pra pedidos ainda não
 *    faturados (o próprio relatório avisa isso) — só ~34% das linhas têm
 *    valor ali. "Preço unitário do anúncio" x "Unidades" está em 100% das
 *    linhas entregues, então o total do pedido é calculado a partir do
 *    item, não copiado da coluna de total.
 *  - Shopee/Centauro: pedidos multi-item vêm em várias linhas com o
 *    cabeçalho (valor total, frete, taxas) REPETIDO idêntico em cada
 *    linha — soma por linha duplicaria o valor; o cabeçalho é lido uma vez
 *    e só os itens são somados.
 *  - Renner: exporta só o pedido, sem SKU/item nenhum — não gera order_items.
 */
class OrderChannelImportController extends Controller
{
    public const TYPES = [
        'mercado_livre' => [
            'title' => 'Importar Vendas Mercado Livre',
            'icon' => 'fa-solid fa-cart-shopping',
            'target' => 'orders + order_items',
            'key_label' => 'N.º de venda',
            'value_label' => 'SKU · Unidades · Preço unitário do anúncio',
            'description' => 'Export "Vendas" do Mercado Livre (aba "Vendas BR"). O valor do pedido é calculado a partir de Unidades × Preço unitário do anúncio — a coluna "Total (BRL)" do arquivo só vem preenchida pra pedidos ainda não faturados, então não é confiável como fonte única.',
            'columns' => ['N.º de venda', 'Data da venda', 'Estado', 'SKU', 'Título do anúncio', 'Unidades', 'Preço unitário de venda do anúncio (BRL)', 'Tarifa de venda e impostos (BRL)', 'Tarifas de envio (BRL)', 'Comprador', 'CPF'],
            'kind' => 'items',
        ],
        'shopee' => [
            'title' => 'Importar Vendas Shopee',
            'icon' => 'fa-solid fa-bag-shopping',
            'target' => 'orders + order_items',
            'key_label' => 'ID do pedido',
            'value_label' => 'SKU · Quantidade · Subtotal do produto',
            'description' => 'Export "orders" da Shopee. Pedidos com mais de um item vêm em várias linhas com o valor total do pedido repetido — o total é lido uma vez por pedido; os itens é que são somados.',
            'columns' => ['ID do pedido', 'Status do pedido', 'Data de criação do pedido', 'Nº de referência SKU', 'Nome do Produto', 'Quantidade', 'Subtotal do produto', 'Valor Total', 'Taxa de comissão bruta', 'Taxa de serviço bruta', 'CPF do Comprador'],
            'kind' => 'items',
        ],
        'centauro' => [
            'title' => 'Importar Vendas Centauro',
            'icon' => 'fa-solid fa-shirt',
            'target' => 'orders + order_items',
            'key_label' => 'NumeroPedido',
            'value_label' => 'SkuCentauro · QuantidadeSku · ValorSku',
            'description' => 'Export de Pedidos da Centauro (CSV ";"). Cruza o item pelo SkuSeller quando presente; a coluna costuma vir vazia no export real, então a maioria dos itens fica sem produto vinculado até a Centauro passar a preenchê-la — o pedido é gravado mesmo assim.',
            'columns' => ['NumeroPedido', 'Account', 'StatusEntrega', 'DataCriacao', 'SkuCentauro', 'SkuSeller', 'QuantidadeSku', 'ValorSku', 'Desconto', 'ValorFrete', 'CpfCnpjCliente', 'CidadeCliente', 'UfCliente'],
            'kind' => 'items',
        ],
        'renner' => [
            'title' => 'Importar Vendas Renner',
            'icon' => 'fa-solid fa-store',
            'target' => 'orders',
            'key_label' => 'Id do Pedido Site',
            'value_label' => 'Valor Total · Status do Pedido',
            'description' => 'Export de Pedidos da Renner (.xlsx). Vem a nível de PEDIDO, sem SKU/item — não gera order_items.',
            'columns' => ['Id do Pedido Site', 'Valor Total', 'Status do Pagamento', 'Status do Pedido', 'Data de Criação do Pedido', 'Forma de Pagamento', 'Número da NF'],
            'kind' => 'orders',
        ],
        'magalu' => [
            'title' => 'Importar Vendas Magazine Luiza',
            'icon' => 'fa-solid fa-cart-shopping',
            'target' => 'orders + order_items',
            'key_label' => 'Número do pedido',
            'value_label' => 'Codigo SKU seller · Valor Total do Item',
            'description' => 'Export de Pedidos do Magazine Luiza (CSV). Captura o valor bruto do pedido e a taxa de serviços do marketplace (comissão + intermediação) como tarifa do canal.',
            'columns' => ['Número do pedido', 'Data do Pedido', 'Canal de vendas', 'Codigo SKU seller', 'Título do produto', 'Valor Total do Item', 'Valor bruto do pedido', 'Último Evento', 'CPF/CNPJ do Cliente'],
            'kind' => 'items',
        ],
    ];

    /* ---------------- progresso ao vivo (cache de arquivo) ---------------- */
    private ?string $progressKey = null;
    private int $progTotal = 0;
    private int $progDone = 0;

    private function initProgress(?string $token, int $total): void
    {
        $this->progressKey = $token ? 'ordch_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
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
            // progresso é best-effort; nunca derruba a importação
        }
    }

    public function progress(string $token)
    {
        $key = 'ordch_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function show(string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);
        $companyId = Auth::user()->company_id;

        return Inertia::render('SalesChannel/OrderImport', [
            'type' => $type,
            'config' => self::TYPES[$type],
            'allTypes' => collect(self::TYPES)->map(fn ($c, $k) => [
                'key' => $k, 'title' => $c['title'], 'icon' => $c['icon'],
            ])->values(),
            'accounts' => SalesChannelAccount::where('company_id', $companyId)
                ->where('channel', $type)->where('is_active', true)
                ->orderBy('label')->get(['id', 'label']),
        ]);
    }

    public function import(Request $request, string $type)
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
            'account_id' => ['required', 'integer'],
        ]);

        $companyId = Auth::user()->company_id;
        $account = SalesChannelAccount::where('id', $request->integer('account_id'))
            ->where('company_id', $companyId)->where('channel', $type)->first();
        if (!$account) {
            return redirect()->route('order-channel.show', ['type' => $type])
                ->with('error', 'Conta inválida — cadastre a conta em Contas por Canal antes de importar.');
        }

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        $expected = in_array($type, ['renner', 'mercado_livre', 'shopee'], true) ? ['xlsx'] : ['csv'];
        if (!in_array($ext, $expected, true)) {
            return redirect()->route('order-channel.show', ['type' => $type])
                ->with('error', 'Formato inesperado (recebido: .' . ($ext ?: '?') . '). Envie o export original do canal (.' . implode('/.', $expected) . ').');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $path = $request->file('file')->getRealPath();
        $this->initProgress((string) $request->input('progress_token', '') ?: null, 0);

        $summary = match ($type) {
            'mercado_livre' => $this->importMercadoLivre($path, $companyId, $account->id),
            'shopee' => $this->importShopee($path, $companyId, $account->id),
            'centauro' => $this->importCentauro($path, $companyId, $account->id),
            'renner' => $this->importRenner($path, $companyId, $account->id),
            'magalu' => $this->importMagalu($path, $companyId, $account->id),
        };

        $this->writeProgress('done', ['result' => $summary]);

        return redirect()->route('order-channel.show', ['type' => $type])
            ->with('importResult', $summary)
            ->with($summary['ok'] ? 'success' : 'error', $summary['message']);
    }

    /* ============================================================
     *  MERCADO LIVRE — "Vendas BR" (.xlsx, cabeçalho na linha 6)
     * ============================================================ */
    private function importMercadoLivre(string $path, int $companyId, int $accountId): array
    {
        $groups = [];
        try {
            $reader = new XlsxReader();
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $sheet) {
                $headerFound = false;
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = $row->toArray();
                    $first = trim((string) ($cells[0] ?? ''));
                    if (!$headerFound) {
                        if ($first === 'N.º de venda') $headerFound = true;
                        continue;
                    }
                    if ($first === '') continue;
                    $this->tick();

                    $g = &$groups[$first];
                    $g ??= ['status' => null, 'date' => null, 'buyer' => null, 'doc' => null, 'city' => null, 'state' => null, 'fee' => 0.0, 'shipping' => 0.0, 'items' => []];

                    $status = trim((string) ($cells[2] ?? ''));
                    $date = trim((string) ($cells[1] ?? ''));
                    $sku = trim((string) ($cells[22] ?? ''));
                    $title = trim((string) ($cells[24] ?? ''));
                    $variacao = trim((string) ($cells[25] ?? ''));
                    $qty = $this->num($cells[6] ?? null);
                    $unitPrice = $this->num($cells[26] ?? null);
                    $fee = $this->num($cells[10] ?? null);
                    $shippingFee = $this->num($cells[12] ?? null);
                    $buyer = trim((string) ($cells[34] ?? ''));
                    $cpf = trim((string) ($cells[36] ?? ''));
                    $city = trim((string) ($cells[38] ?? ''));
                    $state = trim((string) ($cells[39] ?? ''));

                    if ($status !== '') $g['status'] = $status;
                    if ($date !== '') $g['date'] = $date;
                    if ($buyer !== '') $g['buyer'] = $buyer;
                    if ($cpf !== '') $g['doc'] = str_replace('CPF: ', '', $cpf);
                    if ($city !== '') $g['city'] = $city;
                    if ($state !== '') $g['state'] = $state;
                    if ($fee !== null) $g['fee'] += abs($fee);
                    if ($shippingFee !== null) $g['shipping'] += abs($shippingFee);
                    if ($sku !== '') {
                        $g['items'][] = [
                            'sku' => $sku,
                            'title' => trim($title . ($variacao !== '' ? " ({$variacao})" : '')),
                            'quantity' => (int) round($qty ?? 1),
                            'unit_price' => $unitPrice ?? 0.0,
                        ];
                    }
                    unset($g);
                }
                break;
            }
            $reader->close();
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return $this->persistGroups($companyId, $accountId, 'mercado_livre', 'Mercado Livre', $groups,
            fn (string $raw) => $this->mapStatusMercadoLivre($raw),
            fn (string $raw) => $this->parseMlDate($raw)
        );
    }

    private function mapStatusMercadoLivre(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'devolução finalizada') || str_contains($s, 'mediação finalizada') || str_contains($s, 'devolução recusada') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            default => 'approved',
        };
    }

    /** "12 de julho de 2026 17:56 hs." -> Y-m-d H:i:s */
    private function parseMlDate(?string $raw): ?string
    {
        if (!$raw) return null;
        $meses = ['janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'abril' => 4, 'maio' => 5, 'junho' => 6,
            'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10, 'novembro' => 11, 'dezembro' => 12];
        if (!preg_match('/(\d{1,2}) de (\w+) de (\d{4}) (\d{1,2}):(\d{2})/u', mb_strtolower($raw), $m)) {
            return null;
        }
        $mes = $meses[$m[2]] ?? null;
        if (!$mes) return null;

        return sprintf('%04d-%02d-%02d %02d:%02d:00', (int) $m[3], $mes, (int) $m[1], (int) $m[4], (int) $m[5]);
    }

    /* ============================================================
     *  SHOPEE — aba "orders" (.xlsx, cabeçalho na linha 1)
     * ============================================================ */
    private function importShopee(string $path, int $companyId, int $accountId): array
    {
        $groups = [];
        try {
            foreach ($this->readXlsxRows($path) as $row) {
                $this->tick();
                $id = $this->col($row, ['ID do pedido']);
                if (!$id) continue;

                $g = &$groups[$id];
                $g ??= ['status' => null, 'date' => null, 'buyer' => null, 'doc' => null, 'city' => null, 'state' => null,
                    'total' => null, 'fee' => null, 'shipping' => null, 'items' => []];

                $g['status'] = $this->col($row, ['Status do pedido']) ?: $g['status'];
                $g['date'] = $this->col($row, ['Data de criação do pedido']) ?: $g['date'];
                $g['buyer'] = $this->col($row, ['Nome do destinatário']) ?: $g['buyer'];
                $g['doc'] = $this->col($row, ['CPF do Comprador']) ?: $g['doc'];
                $g['city'] = $this->col($row, ['Cidade']) ?: $g['city'];
                $g['state'] = $this->col($row, ['UF']) ?: $g['state'];
                $g['total'] ??= $this->num($this->col($row, ['Valor Total']));
                $g['fee'] ??= $this->num($this->col($row, ['Taxa de comissão bruta'])) + $this->num($this->col($row, ['Taxa de serviço bruta']));
                $g['shipping'] ??= $this->num($this->col($row, ['Valor estimado do frete']));
                $g['payment'] ??= $this->col($row, ['Forma de pagamento']);

                $sku = $this->col($row, ['Número de referência SKU', 'Nº de referência SKU']);
                if ($sku) {
                    $g['items'][] = [
                        'sku' => $sku,
                        'title' => $this->col($row, ['Nome do Produto']),
                        'quantity' => (int) round($this->num($this->col($row, ['Quantidade'])) ?? 1),
                        'unit_price' => $this->num($this->col($row, ['Subtotal do produto'])) ?? 0.0,
                    ];
                }
                unset($g);
            }
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return $this->persistGroups($companyId, $accountId, 'shopee', 'Shopee', $groups,
            fn (string $raw) => $this->mapStatusShopee($raw),
            fn (string $raw) => $this->parseIsoDateTime($raw),
            useTotalDirect: true
        );
    }

    private function mapStatusShopee(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'conclu') || str_contains($s, 'entreg') || str_contains($s, 'devolução até') => 'delivered',
            str_contains($s, 'a enviar') => 'pending',
            str_contains($s, 'enviad') => 'shipped',
            default => 'approved',
        };
    }

    /* ============================================================
     *  CENTAURO — CSV ";" (UTF-8, cabeçalho na linha 1)
     * ============================================================ */
    private function importCentauro(string $path, int $companyId, int $accountId): array
    {
        $groups = [];
        try {
            foreach ($this->readCsvRows($path, ';') as $row) {
                $this->tick();
                $id = $this->col($row, ['NumeroPedido']);
                if (!$id) continue;

                $g = &$groups[$id];
                $g ??= ['status' => null, 'date' => null, 'buyer' => null, 'doc' => null, 'city' => null, 'state' => null,
                    'fee' => null, 'shipping' => null, 'discount' => null, 'items' => []];

                $g['status'] = $this->col($row, ['StatusEntrega']) ?: $g['status'];
                $g['date'] = $this->col($row, ['DataCriacao']) ?: $g['date'];
                $g['doc'] = $this->col($row, ['CpfCnpjCliente']) ?: $g['doc'];
                $g['city'] = $this->col($row, ['CidadeCliente']) ?: $g['city'];
                $g['state'] = $this->col($row, ['UfCliente']) ?: $g['state'];
                $g['shipping'] ??= $this->num($this->col($row, ['ValorFrete']));
                $g['discount'] ??= $this->num($this->col($row, ['Desconto']));

                $sku = $this->col($row, ['SkuSeller']) ?: $this->col($row, ['SkuCentauro']);
                if ($sku) {
                    $g['items'][] = [
                        'sku' => $sku,
                        'title' => $this->col($row, ['NomeProduto']),
                        'quantity' => (int) round($this->num($this->col($row, ['QuantidadeSku'])) ?? 1),
                        'unit_price' => $this->num($this->col($row, ['ValorSku'])) ?? 0.0,
                    ];
                }
                unset($g);
            }
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return $this->persistGroups($companyId, $accountId, 'centauro', 'Centauro', $groups,
            fn (string $raw) => $this->mapStatusCentauro($raw),
            fn (string $raw) => $this->parseIsoDateTime($raw)
        );
    }

    private function mapStatusCentauro(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'recusad') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            str_contains($s, 'enviad') => 'shipped',
            str_contains($s, 'fatur') || str_contains($s, 'pago') => 'approved',
            default => 'pending',
        };
    }

    /* ============================================================
     *  RENNER — .xlsx, nível de pedido (sem item), cabeçalho na linha 1
     * ============================================================ */
    private function importRenner(string $path, int $companyId, int $accountId): array
    {
        $groups = [];
        try {
            foreach ($this->readXlsxRows($path) as $row) {
                $this->tick();
                $id = $this->col($row, ['Id do Pedido Site']);
                if (!$id) continue;

                $groups[$id] = [
                    'status' => $this->col($row, ['Status do Pedido']),
                    'date' => $this->col($row, ['Data de Criação do Pedido']),
                    'buyer' => null, 'doc' => null, 'city' => null, 'state' => null,
                    'total' => $this->num($this->col($row, ['Valor Total'])),
                    'fee' => null, 'shipping' => null,
                    'payment' => $this->col($row, ['Forma de Pagamento']),
                    'items' => [],
                ];
            }
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return $this->persistGroups($companyId, $accountId, 'renner', 'Renner', $groups,
            fn (string $raw) => $this->mapStatusRenner($raw),
            fn (string $raw) => $this->parseBrDateTime($raw),
            useTotalDirect: true
        );
    }

    private function mapStatusRenner(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'entreg') => 'delivered',
            str_contains($s, 'enviad') => 'shipped',
            str_contains($s, 'pago') || str_contains($s, 'nf emitida') => 'approved',
            default => 'pending',
        };
    }

    /* ============================================================
     *  MAGAZINE LUIZA — CSV "," (UTF-8, cabeçalho na linha 1)
     * ============================================================ */
    private function importMagalu(string $path, int $companyId, int $accountId): array
    {
        $groups = [];
        try {
            foreach ($this->readCsvRows($path, ',') as $row) {
                $this->tick();
                $id = $this->col($row, ['Número do pedido']);
                if (!$id) continue;

                $g = &$groups[$id];
                $g ??= ['status' => null, 'date' => null, 'buyer' => null, 'doc' => null, 'city' => null, 'state' => null,
                    'total' => null, 'fee' => null, 'shipping' => null, 'items' => []];

                $g['status'] = $this->col($row, ['Último Evento']) ?: $g['status'];
                $g['date'] = $this->col($row, ['Data do Pedido']) ?: $g['date'];
                $g['buyer'] = $this->col($row, ['Nome do cliente']) ?: $g['buyer'];
                $g['doc'] = $this->col($row, ['CPF/CNPJ do Cliente']) ?: $g['doc'];
                $g['total'] ??= $this->num($this->col($row, ['Valor bruto do pedido']));
                $g['fee'] ??= abs($this->num($this->col($row, ['Serviços do marketplace (1+2+3+4) (Forma de pagamento 1)'])) ?? 0.0);
                $g['payment'] ??= $this->col($row, ['Forma de pagamento 1']);

                $sku = $this->col($row, ['Codigo SKU seller']);
                if ($sku) {
                    $g['items'][] = [
                        'sku' => $sku,
                        'title' => $this->col($row, ['Título do produto']),
                        'quantity' => (int) round($this->num($this->col($row, ['Quantidade de itens'])) ?? 1),
                        'unit_price' => $this->num($this->col($row, ['Valor Total do Item'])) ?? 0.0,
                    ];
                }
                unset($g);
            }
        } catch (\Throwable $e) {
            return $this->fail($e);
        }

        return $this->persistGroups($companyId, $accountId, 'magalu', 'Magazine Luiza', $groups,
            fn (string $raw) => $this->mapStatusMagalu($raw),
            fn (string $raw) => $this->parseBrDateTime($raw),
            useTotalDirect: true
        );
    }

    private function mapStatusMagalu(?string $raw): string
    {
        $s = mb_strtolower(trim((string) $raw));
        return match (true) {
            str_contains($s, 'cancel') => 'cancelled',
            str_contains($s, 'entreg') || str_contains($s, 'retirad') => 'delivered',
            str_contains($s, 'caminho') || str_contains($s, 'enviad') || str_contains($s, 'despachad') => 'shipped',
            default => 'approved',
        };
    }

    /* ============================================================
     *  Persistência comum: grava orders (+ order_items quando houver)
     *  a partir dos grupos já montados por pedido.
     * ============================================================ */
    private function persistGroups(
        int $companyId, int $accountId, string $channelKey, string $channelLabel, array $groups,
        \Closure $mapStatus, \Closure $parseDate, bool $useTotalDirect = false
    ): array {
        if (empty($groups)) {
            return ['ok' => true, 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
                'message' => "Nenhum pedido reconhecido no arquivo enviado ({$channelLabel})."];
        }

        $cols = Schema::getColumnListing('orders');
        $pick = fn (array $c) => collect($c)->first(fn ($x) => in_array($x, $cols, true));
        $keyCol = $pick(['external_id', 'ml_order_id']);
        $nameCol = $pick(['customer_name', 'buyer_nickname']);
        $docCol = $pick(['customer_doc', 'billing_doc_number']);
        $channelCol = $pick(['selling_channel']);
        $accountCol = $pick(['sales_channel_account_id']);
        $payCol = $pick(['payment_method', 'payment_status']);
        $statusCol = $pick(['status']);
        $totalCol = $pick(['total_amount']);
        $paidCol = $pick(['total_paid_amount']);
        $shippingCol = $pick(['shipping_cost']);
        $feeCol = $pick(['marketplace_fee']);
        $discountCol = $pick(['discount_amount']);
        $dateCol = $pick(['date_created', 'order_date']);
        $hasCompany = in_array('company_id', $cols, true);
        $hasTimestamps = in_array('created_at', $cols, true);
        $hasCustomerId = in_array('customer_id', $cols, true);
        $customerIdentity = $hasCustomerId ? app(CustomerIdentityService::class) : null;

        if (!$keyCol) {
            return $this->fail(new \RuntimeException('A tabela orders não possui coluna de identificador (external_id/ml_order_id).'));
        }

        $skuToProduct = Product::where('company_id', $companyId)->whereNotNull('sku')
            ->get(['id', 'sku', 'cost_price'])->keyBy('sku');

        $created = 0; $updated = 0; $skipped = 0; $itemsWritten = 0; $skusNotFound = 0;
        $rows = count($groups);

        DB::beginTransaction();
        try {
            foreach ($groups as $externalId => $g) {
                $itemsTotal = 0.0;
                foreach ($g['items'] as $item) {
                    $itemsTotal += $item['quantity'] * $item['unit_price'];
                }
                $total = $useTotalDirect ? ($g['total'] ?? $itemsTotal) : $itemsTotal;

                $docRaw = $g['doc'] ?? null;
                $nameRaw = $g['buyer'] ?? null;

                $payload = [$keyCol => $externalId];
                if ($nameCol) $payload[$nameCol] = $nameRaw;
                if ($docCol) $payload[$docCol] = $docRaw;
                if ($channelCol) $payload[$channelCol] = $channelLabel;
                if ($accountCol) $payload[$accountCol] = $accountId;
                if ($payCol && !empty($g['payment'])) $payload[$payCol] = $g['payment'];
                if ($statusCol) $payload[$statusCol] = $mapStatus($g['status'] ?? '');
                if ($totalCol) $payload[$totalCol] = round($total, 2);
                if ($paidCol) $payload[$paidCol] = round($total, 2);
                if ($shippingCol && ($g['shipping'] ?? null) !== null) $payload[$shippingCol] = round($g['shipping'], 2);
                if ($feeCol && ($g['fee'] ?? null) !== null) $payload[$feeCol] = round($g['fee'], 2);
                if ($discountCol && ($g['discount'] ?? null) !== null) $payload[$discountCol] = round($g['discount'], 2);
                if ($dateCol) $payload[$dateCol] = $parseDate($g['date'] ?? '');

                if ($customerIdentity) {
                    $customer = $customerIdentity->findOrCreate($companyId, [
                        'doc_number' => $docRaw, 'name' => $nameRaw,
                        'city' => $g['city'] ?? null, 'state' => $g['state'] ?? null,
                        'origin_channel' => $channelLabel,
                    ]);
                    if ($customer) $payload['customer_id'] = $customer->id;
                }

                $query = DB::table('orders')->where($keyCol, $externalId);
                if ($hasCompany) $query->where('company_id', $companyId);
                $existing = $query->first();

                if ($existing) {
                    if ($hasTimestamps) $payload['updated_at'] = now();
                    (clone $query)->update($payload);
                    $orderId = $existing->id;
                    $updated++;
                } else {
                    if ($hasCompany) $payload['company_id'] = $companyId;
                    if ($hasTimestamps) { $payload['created_at'] = now(); $payload['updated_at'] = now(); }
                    $orderId = DB::table('orders')->insertGetId($payload);
                    $created++;
                }

                foreach ($g['items'] as $item) {
                    $product = $skuToProduct->get($item['sku']);
                    if (!$product) $skusNotFound++;
                    $unitCost = ($product && (float) $product->cost_price > 0) ? (float) $product->cost_price : $item['unit_price'] * 0.5;

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

        $msg = "Vendas {$channelLabel}: {$created} pedidos criados, {$updated} atualizados ({$rows} pedidos únicos";
        if ($itemsWritten > 0) {
            $msg .= ", {$itemsWritten} itens gravados";
            if ($skusNotFound > 0) $msg .= ", {$skusNotFound} SKUs não encontrados no catálogo";
        }
        $msg .= ').';

        return ['ok' => true, 'rows' => $rows, 'created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'message' => $msg];
    }

    /* ============================================================
     *  Leitura de arquivo
     * ============================================================ */

    /** XLSX genérico — 1ª linha (não vazia) = cabeçalho, primeira aba. */
    private function readXlsxRows(string $path): \Generator
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
                break;
            }
        } finally {
            $reader->close();
        }
    }

    /** CSV UTF-8 genérico (Centauro ";" / Magalu ","), 1ª linha = cabeçalho. */
    private function readCsvRows(string $path, string $delimiter): \Generator
    {
        $fh = fopen($path, 'r');
        if ($fh === false) throw new \RuntimeException('Não foi possível abrir o arquivo.');

        // remove BOM UTF-8 se houver
        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($fh);

        try {
            $header = fgetcsv($fh, 0, $delimiter);
            if ($header === false) return;
            $header = array_map(fn ($h) => trim((string) $h), $header);

            while (($cells = fgetcsv($fh, 0, $delimiter)) !== false) {
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
        } finally {
            fclose($fh);
        }
    }

    private function col(array $row, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            foreach ($row as $header => $value) {
                if (mb_strtolower(trim((string) $header)) === mb_strtolower($c)) {
                    if ($value === null) return null;
                    $s = is_string($value) ? $value : (string) $value;

                    return trim($s) === '' ? null : trim($s);
                }
            }
        }

        return null;
    }

    /** Aceita ponto (padrão desses exports) ou vírgula (BR) como decimal, e prefixo "R$". */
    private function num($value): ?float
    {
        if ($value === null) return null;
        if (is_int($value) || is_float($value)) return (float) $value;
        $v = trim((string) $value);
        if ($v === '') return null;
        $v = str_replace(['R$', ' ', "\xC2\xA0"], '', $v);
        $hasComma = str_contains($v, ',');
        $hasDot = str_contains($v, '.');
        if ($hasComma && $hasDot) {
            $v = str_replace('.', '', $v);
            $v = str_replace(',', '.', $v);
        } elseif ($hasComma) {
            $v = str_replace(',', '.', $v);
        }

        return is_numeric($v) ? (float) $v : null;
    }

    /** "2026-07-01 20:19" / "2026-01-03 17:57:27.26" -> Y-m-d H:i:s */
    private function parseIsoDateTime(?string $raw): ?string
    {
        if (!$raw) return null;
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?/', trim($raw), $m)) {
            return $m[1] . ' ' . $m[2] . ($m[3] ?? ':00');
        }

        return null;
    }

    /** "04/06/2026 14:30:43" -> Y-m-d H:i:s */
    private function parseBrDateTime(?string $raw): ?string
    {
        if (!$raw) return null;
        foreach (['!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y'] as $fmt) {
            try {
                $dt = \Illuminate\Support\Carbon::createFromFormat($fmt, trim($raw));
                if ($dt !== false) return $dt->toDateTimeString();
            } catch (\Throwable $e) {
                // tenta o próximo formato
            }
        }

        return null;
    }

    private function fail(\Throwable $e): array
    {
        return [
            'ok' => false, 'rows' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0,
            'message' => 'Falha na importação (nada foi gravado): ' . $e->getMessage(),
        ];
    }
}
