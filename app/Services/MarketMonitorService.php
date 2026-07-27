<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de competitividade (estilo Hooklab). Compara o preço praticado do
 * produto com o `market_price` (melhor preço do concorrente) e deriva o status:
 *
 *   - desconhecido : sem preço de mercado ainda
 *   - alerta       : sem estoque (precisa de atenção)
 *   - perdendo     : nosso preço está acima do mercado
 *   - vendendo     : nosso preço está igual/abaixo do mercado
 *
 * Toda leitura é defensiva ao schema (Schema::hasColumn) e feita via query
 * builder — sem carregar dezenas de milhares de produtos em memória.
 */
class MarketMonitorService
{
    /** Expressão SQL do preço efetivo (promo -> venda -> price). */
    private function effectivePriceSql(): string
    {
        $parts = [];
        foreach (['promotional_price', 'sale_price', 'price'] as $c) {
            if (Schema::hasColumn('products', $c)) {
                $parts[] = "NULLIF($c, 0)";
            }
        }
        return empty($parts) ? '0' : 'COALESCE(' . implode(', ', $parts) . ', 0)';
    }

    /** Expressão SQL do status de competitividade. */
    private function statusSql(): string
    {
        $eff = $this->effectivePriceSql();
        $stock = Schema::hasColumn('products', 'stock_quantity') ? 'stock_quantity' : '0';
        return "CASE
            WHEN market_price IS NULL OR market_price = 0 THEN 'desconhecido'
            WHEN COALESCE($stock, 0) <= 0 THEN 'alerta'
            WHEN $eff > market_price THEN 'perdendo'
            ELSE 'vendendo'
        END";
    }

    /** Query base do escopo monitorado da empresa. */
    private function scope(int $companyId)
    {
        $q = DB::table('products');
        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $companyId);
        }
        if (Schema::hasColumn('products', 'monitored')) {
            $q->where('monitored', true);
        }
        return $q;
    }

    /** KPIs + distribuição + evolução para o dashboard. */
    public function summary(int $companyId, int $days = 30): array
    {
        if (!Schema::hasTable('products')) {
            return $this->empty();
        }

        $status = $this->statusSql();
        $eff = $this->effectivePriceSql();

        // Contagem por status (uma passada agregada).
        $byStatus = $this->scope($companyId)
            ->selectRaw("$status as st, COUNT(*) as c")
            ->groupBy('st')->pluck('c', 'st');

        $vendendo = (int) ($byStatus['vendendo'] ?? 0);
        $perdendo = (int) ($byStatus['perdendo'] ?? 0);
        $alerta = (int) ($byStatus['alerta'] ?? 0);
        $desconhecido = (int) ($byStatus['desconhecido'] ?? 0);
        $total = $vendendo + $perdendo + $alerta + $desconhecido;
        $comMercado = $vendendo + $perdendo + $alerta;

        // Por marketplace (canal de venda).
        $porCanal = [];
        if (Schema::hasColumn('products', 'selling_channel')) {
            $porCanal = $this->scope($companyId)
                ->selectRaw("COALESCE(NULLIF(selling_channel,''),'—') as canal, COUNT(*) as total,
                    SUM(CASE WHEN ($status)='perdendo' THEN 1 ELSE 0 END) as perdendo,
                    SUM(CASE WHEN ($status)='vendendo' THEN 1 ELSE 0 END) as vendendo")
                ->groupBy('canal')->orderByDesc('total')->limit(12)->get()
                ->map(fn ($r) => [
                    'canal' => $r->canal,
                    'total' => (int) $r->total,
                    'perdendo' => (int) $r->perdendo,
                    'vendendo' => (int) $r->vendendo,
                ])->all();
        }

        // Oportunidades: perdendo com gap pequeno (<= 5%) — ajuste fácil.
        $oportunidades = (int) $this->scope($companyId)
            ->whereRaw("($status) = 'perdendo'")
            ->whereRaw("$eff <= market_price * 1.05")
            ->count();

        // Exposição: soma do preço efetivo dos produtos que estão perdendo.
        $exposicao = (float) $this->scope($companyId)
            ->whereRaw("($status) = 'perdendo'")
            ->sum(DB::raw($eff));

        // Gap médio (%) entre os que têm mercado.
        $gapMedio = (float) $this->scope($companyId)
            ->whereRaw("market_price IS NOT NULL AND market_price > 0")
            ->avg(DB::raw("($eff - market_price) / market_price * 100"));

        // Recentes: verificados nas últimas 24h.
        $recentes = 0;
        if (Schema::hasColumn('products', 'market_checked_at')) {
            $recentes = (int) $this->scope($companyId)
                ->where('market_checked_at', '>=', now()->subDay())->count();
        }

        return [
            'kpis' => [
                'monitorados' => $total,
                'com_mercado' => $comMercado,
                'vendendo' => $vendendo,
                'perdendo' => $perdendo,
                'alerta' => $alerta,
                'desconhecido' => $desconhecido,
                'oportunidades' => $oportunidades,
                'exposicao' => round($exposicao, 2),
                'gap_medio' => round($gapMedio ?: 0, 2),
                'recentes_24h' => $recentes,
            ],
            'distribuicao' => [
                ['label' => 'Vendendo', 'value' => $vendendo, 'color' => '#10b981'],
                ['label' => 'Perdendo', 'value' => $perdendo, 'color' => '#ef4444'],
                ['label' => 'Alerta', 'value' => $alerta, 'color' => '#f59e0b'],
                ['label' => 'Desconhecido', 'value' => $desconhecido, 'color' => '#94a3b8'],
            ],
            'por_canal' => $porCanal,
            'evolucao' => $this->evolucao($companyId, $days),
            'has_data' => $total > 0,
        ];
    }

    /** Evolução de faturamento por dia (a partir dos pedidos importados). */
    private function evolucao(int $companyId, int $days): array
    {
        if (!Schema::hasTable('orders')) {
            return [];
        }
        $cols = Schema::getColumnListing('orders');
        $totalCol = in_array('total_amount', $cols, true) ? 'total_amount' : null;
        $dateCol = in_array('date_created', $cols, true) ? 'date_created'
            : (in_array('order_date', $cols, true) ? 'order_date' : null);
        if (!$totalCol || !$dateCol) {
            return [];
        }

        try {
            $q = DB::table('orders');
            if (in_array('company_id', $cols, true)) {
                $q->where('company_id', $companyId);
            }
            return $q->where($dateCol, '>=', Carbon::now()->subDays($days))
                ->selectRaw("DATE($dateCol) as dia, SUM($totalCol) as total")
                ->groupBy(DB::raw("DATE($dateCol)"))->orderBy('dia')->get()
                ->map(fn ($r) => ['dia' => $r->dia, 'total' => (float) $r->total])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Lista paginada de produtos monitorados, com filtros/ordenação/busca. */
    public function products(int $companyId, array $filters = []): array
    {
        if (!Schema::hasTable('products')) {
            return ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'last_page' => 1];
        }

        $status = $this->statusSql();
        $eff = $this->effectivePriceSql();
        $has = fn ($c) => Schema::hasColumn('products', $c);

        $titleCol = $has('title') ? 'title' : ($has('name') ? 'name' : 'sku');

        $select = [
            'id',
            "$titleCol as titulo",
            DB::raw("$eff as preco"),
            DB::raw("($status) as status"),
        ];
        foreach (['sku', 'brand', 'selling_channel', 'market_price', 'market_seller',
                  'stock_quantity', 'market_checked_at'] as $c) {
            if ($has($c)) $select[] = $c;
        }

        $q = $this->scope($companyId)->select($select);

        // Filtro por status / marketplace.
        $filter = $filters['filter'] ?? 'all';
        if (in_array($filter, ['vendendo', 'perdendo', 'alerta', 'desconhecido'], true)) {
            $q->whereRaw("($status) = ?", [$filter]);
        }
        if (!empty($filters['marketplace']) && $has('selling_channel')) {
            $q->where('selling_channel', $filters['marketplace']);
        }
        if (!empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $q->where(function ($w) use ($term, $titleCol, $has) {
                $w->where($titleCol, 'like', $term);
                if ($has('sku')) $w->orWhere('sku', 'like', $term);
            });
        }

        // Ordenação.
        $sort = $filters['sort'] ?? 'titulo';
        $dir = ($filters['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';
        $sortCol = match ($sort) {
            'preco' => DB::raw($eff),
            'seller' => 'market_seller',
            'market' => 'market_price',
            default => $titleCol,
        };
        $q->orderBy($sortCol, $dir);

        $perPage = 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $total = (clone $q)->count();
        $rows = $q->forPage($page, $perPage)->get()->map(function ($r) {
            $preco = (float) ($r->preco ?? 0);
            $market = isset($r->market_price) ? (float) $r->market_price : null;
            $gap = ($market && $market > 0) ? round(($preco - $market) / $market * 100, 1) : null;
            return [
                'id' => $r->id,
                'titulo' => $r->titulo,
                'sku' => $r->sku ?? null,
                'marca' => $r->brand ?? null,
                'canal' => $r->selling_channel ?? null,
                'preco' => round($preco, 2),
                'market_price' => $market,
                'gap' => $gap,
                'seller' => $r->market_seller ?? null,
                'estoque' => isset($r->stock_quantity) ? (int) $r->stock_quantity : null,
                'status' => $r->status,
                'checked_at' => $r->market_checked_at ?? null,
            ];
        })->all();

        return [
            'data' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    private function empty(): array
    {
        return [
            'kpis' => [
                'monitorados' => 0, 'com_mercado' => 0, 'vendendo' => 0, 'perdendo' => 0,
                'alerta' => 0, 'desconhecido' => 0, 'oportunidades' => 0,
                'exposicao' => 0, 'gap_medio' => 0, 'recentes_24h' => 0,
            ],
            'distribuicao' => [],
            'por_canal' => [],
            'evolucao' => [],
            'has_data' => false,
        ];
    }
}
