<?php

namespace App\Services\Sales;

use App\Models\Order;
use App\Services\Customers\CustomerIdentityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de agregações da Central de Vendas (/sales).
 *
 * Centraliza a resolução da coluna de data (date_created -> order_date ->
 * created_at, CLAUDE.md §5.1) num único lugar. Essa mesma lógica já existia
 * reimplementada de formas ligeiramente diferentes em SalesController,
 * FinancialProrationService e ReportController — daqui pra frente, qualquer
 * leitura nova da tela de Vendas passa por este serviço, não reinventa de novo.
 *
 * Toda leitura é defensiva ao schema variável (CLAUDE.md §4): nenhuma query
 * assume que uma coluna existe sem checar antes.
 *
 * Período: todo método aceita `$days` (janela relativa a agora, comportamento
 * original) e, opcionalmente, `$range` — um par `[Carbon $since, Carbon
 * $until]` já resolvido pelo controller (data personalizada ou mês
 * específico). Quando `$range` é passado, ele SUBSTITUI `$days` por
 * completo. Mantido assim (em vez de trocar a assinatura) pra não quebrar
 * nenhum chamador/teste existente que já passa `$days` como int.
 */
class SalesAnalyticsService
{
    private const REQUIRED_TABLE = 'orders';

    /** Estados brasileiros por região — só usado pra agrupar o gráfico de
     *  macrorregião a partir do UF já lido em customers.state. Não temos
     *  como saber se o dado vem como "SP" ou "São Paulo" nas integrações,
     *  então só mapeamos o UF de 2 letras; qualquer outro formato cai em
     *  "Não identificado" (nunca chutamos a região). */
    private const UF_REGIAO = [
        'AC' => 'Norte', 'AP' => 'Norte', 'AM' => 'Norte', 'PA' => 'Norte', 'RO' => 'Norte', 'RR' => 'Norte', 'TO' => 'Norte',
        'AL' => 'Nordeste', 'BA' => 'Nordeste', 'CE' => 'Nordeste', 'MA' => 'Nordeste', 'PB' => 'Nordeste', 'PE' => 'Nordeste', 'PI' => 'Nordeste', 'RN' => 'Nordeste', 'SE' => 'Nordeste',
        'DF' => 'Centro-Oeste', 'GO' => 'Centro-Oeste', 'MT' => 'Centro-Oeste', 'MS' => 'Centro-Oeste',
        'ES' => 'Sudeste', 'MG' => 'Sudeste', 'RJ' => 'Sudeste', 'SP' => 'Sudeste',
        'PR' => 'Sul', 'RS' => 'Sul', 'SC' => 'Sul',
    ];

    public function schemaReady(): bool
    {
        return Schema::hasTable(self::REQUIRED_TABLE)
            && in_array('total_amount', Schema::getColumnListing(self::REQUIRED_TABLE), true);
    }

    /** date_created -> order_date -> created_at, nessa ordem, só se a coluna existir. */
    public function resolveDateColumn(array $cols): ?string
    {
        foreach (['date_created', 'order_date', 'created_at'] as $c) {
            if (in_array($c, $cols, true)) {
                return $c;
            }
        }
        return null;
    }

    /** Resolve a janela [since, until]: $range (se informado) sempre vence $days. */
    private function resolveWindow(int $days, ?array $range): array
    {
        if ($range) {
            return [$range[0], $range[1]];
        }
        return [Carbon::now()->subDays($days), Carbon::now()];
    }

    /**
     * KPIs do período + variação frente ao período imediatamente anterior de
     * mesma duração (ex: últimos 30 dias vs os 30 dias antes desses; ou, com
     * range customizado, os mesmos N dias imediatamente antes do início).
     */
    public function kpis(int $companyId, int $days, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        $has = fn ($c) => in_array($c, $cols, true);
        $totalCol = $has('total_amount') ? 'total_amount' : null;
        if (!$totalCol) {
            return $this->emptyKpis();
        }

        $statusCol = $has('status') ? 'status' : null;
        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = $has('company_id');

        [$since, $until] = $this->resolveWindow($days, $range);
        $durationDays = max(1, (int) $since->diffInDays($until));
        $previousSince = $since->copy()->subDays($durationDays);
        $previousUntil = $since->copy();

        $scope = function ($from, $to) use ($companyId, $hasCompany, $dateCol) {
            $q = DB::table('orders');
            if ($hasCompany) {
                $q->where('company_id', $companyId);
            }
            if ($dateCol) {
                $q->where($dateCol, '>=', $from)->where($dateCol, '<=', $to);
            }
            return $q;
        };
        $faturado = function ($q) use ($statusCol) {
            if ($statusCol) {
                $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
            }
            return $q;
        };

        $faturamento = (float) $faturado($scope($since, $until))->sum($totalCol);
        $pedidos = (int) $faturado($scope($since, $until))->count();

        $faturamentoAnterior = $dateCol ? (float) $faturado($scope($previousSince, $previousUntil))->sum($totalCol) : 0.0;

        $cancelados = 0;
        $canceladoValor = 0.0;
        if ($statusCol) {
            $cancelados = (int) $scope($since, $until)->where($statusCol, 'cancelled')->count();
            $canceladoValor = (float) $scope($since, $until)->where($statusCol, 'cancelled')->sum($totalCol);
        }
        $taxaCancelamentoPct = ($pedidos + $cancelados) > 0 ? round($cancelados / ($pedidos + $cancelados) * 100, 1) : 0.0;

        return [
            'faturamento' => round($faturamento, 2),
            'pedidos' => $pedidos,
            'ticket' => $pedidos > 0 ? round($faturamento / $pedidos, 2) : 0,
            'cancelados' => $cancelados,
            'cancelado_valor' => round($canceladoValor, 2),
            'taxa_cancelamento_pct' => $taxaCancelamentoPct,
            'variacao_pct' => $this->pctChange($faturamento, $faturamentoAnterior),
        ];
    }

    public function porCanal(int $companyId, int $days, int $limit = 12, ?array $range = null): array
    {
        return $this->groupedTotals($companyId, $days, 'selling_channel', 'canal', $limit, $range);
    }

    public function porStatus(int $companyId, int $days, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        if (!in_array('status', $cols, true) || !in_array('total_amount', $cols, true)) {
            return [];
        }

        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders');
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($dateCol) {
            $q->where($dateCol, '>=', $since)->where($dateCol, '<=', $until);
        }

        return $q->select(DB::raw('status as status'), DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as pedidos'))
            ->groupBy('status')->orderByDesc('pedidos')->get()
            ->map(fn ($r) => ['status' => $r->status ?: 'indefinido', 'total' => (float) $r->total, 'pedidos' => (int) $r->pedidos])
            ->all();
    }

    public function porDia(int $companyId, int $days, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        $dateCol = $this->resolveDateColumn($cols);
        if (!$dateCol || !in_array('total_amount', $cols, true)) {
            return [];
        }

        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders')->where($dateCol, '>=', $since)->where($dateCol, '<=', $until);
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($statusCol) {
            $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
        }

        return $q->select(DB::raw("DATE($dateCol) as dia"), DB::raw('SUM(total_amount) as total'))
            ->groupBy(DB::raw("DATE($dateCol)"))->orderBy('dia')->get()
            ->map(fn ($r) => ['dia' => $r->dia, 'total' => (float) $r->total])->all();
    }

    /**
     * Tendência mensal — janela fixa (independe do filtro de período da
     * página, igual ao histórico do Dashboard Financeiro), com meses sem
     * venda preenchidos com zero em vez de sumir do gráfico.
     */
    public function tendenciaMensal(int $companyId, int $months = 12): array
    {
        $cols = Schema::getColumnListing('orders');
        $dateCol = $this->resolveDateColumn($cols);
        if (!$dateCol || !in_array('total_amount', $cols, true)) {
            return [];
        }

        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        $since = Carbon::now()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $q = DB::table('orders')->where($dateCol, '>=', $since);
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($statusCol) {
            $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
        }

        $rows = $q->select(
            DB::raw("DATE_FORMAT($dateCol, '%Y-%m') as mes"),
            DB::raw('SUM(total_amount) as total'),
            DB::raw('COUNT(*) as pedidos')
        )->groupBy(DB::raw("DATE_FORMAT($dateCol, '%Y-%m')"))->get()->keyBy('mes');

        $out = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = Carbon::now()->startOfMonth()->subMonthsNoOverflow($i);
            $key = $m->format('Y-m');
            $row = $rows->get($key);
            $out[] = [
                'mes' => $key,
                'label' => $m->translatedFormat('M/y'),
                'total' => $row ? (float) $row->total : 0.0,
                'pedidos' => $row ? (int) $row->pedidos : 0,
            ];
        }

        return $out;
    }

    /**
     * Vendas por estado (customers.state) — join via orders.customer_id.
     * Exige a tabela/coluna existirem; sem elas, retorna lista vazia (não
     * finge que existe dado de região).
     */
    public function porRegiaoEstado(int $companyId, int $days, int $limit = 15, ?array $range = null): array
    {
        if (!$this->canJoinCustomers()) {
            return [];
        }

        $cols = Schema::getColumnListing('orders');
        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders as o')
            ->join('customers as c', 'c.id', '=', 'o.customer_id')
            ->whereNotNull('c.state')->where('c.state', '!=', '');
        if ($hasCompany) {
            $q->where('o.company_id', $companyId);
        }
        if ($dateCol) {
            $q->where("o.$dateCol", '>=', $since)->where("o.$dateCol", '<=', $until);
        }
        if ($statusCol) {
            $q->whereIn("o.$statusCol", Order::CONFIRMED_STATUSES);
        }

        return $q->select('c.state as estado', DB::raw('SUM(o.total_amount) as total'), DB::raw('COUNT(*) as pedidos'))
            ->groupBy('c.state')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['estado' => $r->estado, 'total' => (float) $r->total, 'pedidos' => (int) $r->pedidos])
            ->all();
    }

    /** Rollup das 5 macrorregiões a partir do mesmo dado de porRegiaoEstado(), sem 2ª query. */
    public function porRegiaoMacro(array $porRegiaoEstado): array
    {
        $buckets = ['Norte' => 0.0, 'Nordeste' => 0.0, 'Centro-Oeste' => 0.0, 'Sudeste' => 0.0, 'Sul' => 0.0, 'Não identificado' => 0.0];
        $pedidos = ['Norte' => 0, 'Nordeste' => 0, 'Centro-Oeste' => 0, 'Sudeste' => 0, 'Sul' => 0, 'Não identificado' => 0];

        foreach ($porRegiaoEstado as $row) {
            $uf = strtoupper(trim((string) $row['estado']));
            $regiao = self::UF_REGIAO[$uf] ?? 'Não identificado';
            $buckets[$regiao] += $row['total'];
            $pedidos[$regiao] += $row['pedidos'];
        }

        $out = [];
        foreach ($buckets as $regiao => $total) {
            if ($total <= 0 && $pedidos[$regiao] === 0) {
                continue;
            }
            $out[] = ['regiao' => $regiao, 'total' => round($total, 2), 'pedidos' => $pedidos[$regiao]];
        }

        usort($out, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $out;
    }

    public function porMarca(int $companyId, int $days, int $limit = 10, ?array $range = null): array
    {
        if (!$this->canJoinOrderItems() || !Schema::hasColumn('products', 'brand')) {
            return [];
        }

        [$q, ] = $this->orderItemsQuery($companyId, $days, $range);
        $q->join('products as p', 'p.id', '=', 'oi.product_id')
            ->whereNotNull('p.brand')->where('p.brand', '!=', '');

        return $q->select('p.brand as marca', DB::raw('SUM(oi.unit_price * oi.quantity) as total'), DB::raw('SUM(oi.quantity) as unidades'))
            ->groupBy('p.brand')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['marca' => $r->marca, 'total' => (float) $r->total, 'unidades' => (int) $r->unidades])
            ->all();
    }

    public function topProdutos(int $companyId, int $days, int $limit = 10, ?array $range = null): array
    {
        if (!$this->canJoinOrderItems()) {
            return [];
        }

        [$q, ] = $this->orderItemsQuery($companyId, $days, $range);

        return $q->select(
            DB::raw('COALESCE(oi.title, oi.sku) as titulo'),
            'oi.sku as sku',
            DB::raw('SUM(oi.unit_price * oi.quantity) as total'),
            DB::raw('SUM(oi.quantity) as unidades'),
            DB::raw('COUNT(DISTINCT oi.order_id) as pedidos')
        )->groupBy('oi.sku', DB::raw('COALESCE(oi.title, oi.sku)'))
            ->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => [
                'titulo' => $r->titulo ?: '—', 'sku' => $r->sku, 'total' => (float) $r->total,
                'unidades' => (int) $r->unidades, 'pedidos' => (int) $r->pedidos,
                'preco_medio' => $r->unidades > 0 ? round($r->total / $r->unidades, 2) : 0.0,
            ])
            ->all();
    }

    /**
     * Top clientes por faturamento no período, agrupados por CPF/CNPJ
     * normalizado (não por customer_id — funciona mesmo em pedidos antigos
     * sem vínculo com `customers`, mesma lógica de CustomerController).
     * `canais` traz todo canal em que aquele CPF comprou no período —
     * `multicanal` fica true quando são 2 ou mais, pedido explícito do
     * cliente (01/08/2026): "caso ele tenha comprado em mais de um canal,
     * preciso saber disso".
     */
    public function topClientes(int $companyId, int $days, int $limit = 10, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        $docExpr = $this->docExpr($cols);
        if (!$docExpr || !in_array('total_amount', $cols, true)) {
            return [];
        }

        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        $channelCol = in_array('selling_channel', $cols, true) ? 'selling_channel' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders')->whereRaw("$docExpr IS NOT NULL AND $docExpr != ''");
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($dateCol) {
            $q->where($dateCol, '>=', $since)->where($dateCol, '<=', $until);
        }
        if ($statusCol) {
            $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
        }

        $select = [
            DB::raw("$docExpr as doc"),
            DB::raw('MAX(' . $this->nameExpr($cols) . ') as nome'),
            DB::raw('COUNT(*) as pedidos'),
            DB::raw('SUM(total_amount) as total'),
            DB::raw('MAX(' . ($dateCol ?: 'created_at') . ') as ultima_compra'),
        ];
        $select[] = $channelCol
            ? DB::raw("GROUP_CONCAT(DISTINCT $channelCol SEPARATOR ',') as canais_raw")
            : DB::raw('NULL as canais_raw');

        return $q->select($select)
            ->groupBy(DB::raw($docExpr))
            ->orderByDesc('total')
            ->limit($limit)
            ->get()
            ->map(function ($r) {
                $canais = $r->canais_raw ? array_values(array_filter(explode(',', $r->canais_raw))) : [];

                return [
                    'doc' => $r->doc,
                    'nome' => $r->nome ?: '—',
                    'pedidos' => (int) $r->pedidos,
                    'total' => (float) $r->total,
                    'ticket_medio' => $r->pedidos > 0 ? round($r->total / $r->pedidos, 2) : 0.0,
                    'ultima_compra' => $r->ultima_compra,
                    'canais' => $canais,
                    'multicanal' => count($canais) > 1,
                ];
            })
            ->all();
    }

    /**
     * Resumo de clientes do período: quantos compraram (por CPF único) e
     * quantos desses compraram em 2+ canais diferentes. É a métrica-resumo
     * do pedido "preciso saber disso" — não fica só escondida em top 10.
     */
    public function clientesResumo(int $companyId, int $days, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        $docExpr = $this->docExpr($cols);
        if (!$docExpr) {
            return ['total_clientes' => 0, 'multicanal' => 0];
        }

        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        $channelCol = in_array('selling_channel', $cols, true) ? 'selling_channel' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders')->whereRaw("$docExpr IS NOT NULL AND $docExpr != ''");
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($dateCol) {
            $q->where($dateCol, '>=', $since)->where($dateCol, '<=', $until);
        }
        if ($statusCol) {
            $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
        }

        $canaisSelect = $channelCol ? DB::raw("COUNT(DISTINCT $channelCol) as canais") : DB::raw('1 as canais');
        $sub = (clone $q)->select([DB::raw("$docExpr as doc"), $canaisSelect])->groupBy(DB::raw($docExpr));

        $totalClientes = DB::query()->fromSub($sub, 't')->count();
        $multicanal = $channelCol ? DB::query()->fromSub($sub, 't')->where('canais', '>=', 2)->count() : 0;

        return ['total_clientes' => $totalClientes, 'multicanal' => $multicanal];
    }

    private function groupedTotals(int $companyId, int $days, string $column, string $alias, int $limit, ?array $range): array
    {
        $cols = Schema::getColumnListing('orders');
        if (!in_array($column, $cols, true) || !in_array('total_amount', $cols, true)) {
            return [];
        }

        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('orders');
        if ($hasCompany) {
            $q->where('company_id', $companyId);
        }
        if ($dateCol) {
            $q->where($dateCol, '>=', $since)->where($dateCol, '<=', $until);
        }
        if ($statusCol) {
            $q->whereIn($statusCol, Order::CONFIRMED_STATUSES);
        }

        return $q->select(DB::raw("$column as $alias"), DB::raw('SUM(total_amount) as total'), DB::raw('COUNT(*) as pedidos'))
            ->groupBy($column)->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => [$alias => $r->$alias ?: 'Sem canal', 'total' => (float) $r->total, 'pedidos' => (int) $r->pedidos])
            ->all();
    }

    private function orderItemsQuery(int $companyId, int $days, ?array $range = null): array
    {
        $cols = Schema::getColumnListing('orders');
        $dateCol = $this->resolveDateColumn($cols);
        $hasCompany = in_array('company_id', $cols, true);
        $statusCol = in_array('status', $cols, true) ? 'status' : null;
        [$since, $until] = $this->resolveWindow($days, $range);

        $q = DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id');
        if ($hasCompany) {
            $q->where('o.company_id', $companyId);
        }
        if ($dateCol) {
            $q->where("o.$dateCol", '>=', $since)->where("o.$dateCol", '<=', $until);
        }
        if ($statusCol) {
            $q->whereIn("o.$statusCol", Order::CONFIRMED_STATUSES);
        }

        return [$q, $dateCol];
    }

    private function canJoinCustomers(): bool
    {
        return Schema::hasTable('customers')
            && Schema::hasColumn('customers', 'state')
            && in_array('customer_id', Schema::getColumnListing('orders'), true);
    }

    private function canJoinOrderItems(): bool
    {
        return Schema::hasTable('order_items') && Schema::hasTable('products')
            && in_array('product_id', Schema::getColumnListing('order_items'), true);
    }

    /** Expressão SQL do CPF/CNPJ normalizado do pedido, ou null se nenhuma das colunas existir. */
    private function docExpr(array $cols): ?string
    {
        $hasBilling = in_array('billing_doc_number', $cols, true);
        $hasCustomerDoc = in_array('customer_doc', $cols, true);
        if (!$hasBilling && !$hasCustomerDoc) {
            return null;
        }

        return CustomerIdentityService::sqlDocExpr(
            $hasBilling ? 'billing_doc_number' : 'NULL',
            $hasCustomerDoc ? 'customer_doc' : 'NULL'
        );
    }

    /** customer_name (qualquer canal) -> buyer_nickname (legado, só ML) -> '—'. */
    private function nameExpr(array $cols): string
    {
        $hasName = in_array('customer_name', $cols, true);
        $hasNickname = in_array('buyer_nickname', $cols, true);
        if ($hasName && $hasNickname) {
            return 'COALESCE(customer_name, buyer_nickname)';
        }
        if ($hasName) {
            return 'customer_name';
        }
        if ($hasNickname) {
            return 'buyer_nickname';
        }
        return "'—'";
    }

    private function pctChange(float $current, float $previous): ?float
    {
        if ($previous <= 0) {
            return null; // sem base de comparação — não inventa "infinito%" ou 0%
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function emptyKpis(): array
    {
        return [
            'faturamento' => 0, 'pedidos' => 0, 'ticket' => 0, 'cancelados' => 0,
            'cancelado_valor' => 0, 'taxa_cancelamento_pct' => 0.0, 'variacao_pct' => null,
        ];
    }
}
