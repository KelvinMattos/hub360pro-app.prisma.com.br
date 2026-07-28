<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Otimização e relatórios de competitividade.
 *
 *  - Oportunidades: produtos perdendo, com o preço sugerido para reassumir a
 *    Buy Box e o quanto isso custa em margem (respeitando o piso de custo).
 *  - Relatório: distribuição por marca e classificação de concorrência.
 *  - Buy Box: quem está ganhando/perdendo (base do que o gestor pergunta).
 */
class MarketOptimizerService
{
    private function has(string $c): bool
    {
        return Schema::hasColumn('products', $c);
    }

    /** Preço efetivo praticado (promo -> venda -> price). */
    private function effSql(): string
    {
        $parts = [];
        foreach (['promotional_price', 'sale_price', 'price'] as $c) {
            if ($this->has($c)) {
                $parts[] = "NULLIF($c, 0)";
            }
        }
        return empty($parts) ? '0' : 'COALESCE(' . implode(', ', $parts) . ', 0)';
    }

    private function scope(int $companyId)
    {
        $q = DB::table('products');
        if ($this->has('company_id')) {
            $q->where('company_id', $companyId);
        }
        if ($this->has('monitored')) {
            $q->where('monitored', true);
        }
        return $q;
    }

    /** Só produtos com preço de mercado conhecido. */
    private function withMarket(int $companyId)
    {
        return $this->scope($companyId)
            ->whereNotNull('market_price')->where('market_price', '>', 0);
    }

    /* ============================ BUY BOX ============================ */

    /** Quantos estamos ganhando / perdendo / sem informação. */
    public function buybox(int $companyId): array
    {
        if (!Schema::hasTable('products') || !$this->has('buybox_winner')) {
            return ['ganhando' => 0, 'perdendo' => 0, 'sem_info' => 0, 'total' => 0, 'share' => 0];
        }

        $ganhando = (int) $this->scope($companyId)->where('buybox_winner', true)->count();
        $perdendo = (int) $this->scope($companyId)->where('buybox_winner', false)->count();

        $semInfo = (int) $this->scope($companyId)
            ->whereNull('buybox_winner')
            ->when($this->has('netshoes_sku'), fn ($q) => $q->whereNotNull('netshoes_sku'))
            ->count();

        $total = $ganhando + $perdendo;

        return [
            'ganhando' => $ganhando,
            'perdendo' => $perdendo,
            'sem_info' => $semInfo,
            'total' => $total,
            'share' => $total > 0 ? round($ganhando / $total * 100, 1) : 0,
        ];
    }

    /** Lista de produtos por situação de Buy Box (ganhando|perdendo|sem_info). */
    public function buyboxList(int $companyId, string $situacao = 'perdendo', int $limit = 300): array
    {
        if (!Schema::hasTable('products') || !$this->has('buybox_winner')) {
            return [];
        }

        $eff = $this->effSql();
        $sel = ['id', DB::raw("$eff as preco")];
        foreach (['sku', 'netshoes_sku', 'brand', 'market_price', 'market_seller', 'market_url',
                  'market_offers_count', 'buybox_winner', 'market_checked_at'] as $c) {
            if ($this->has($c)) {
                $sel[] = $c;
            }
        }
        $sel[] = ($this->has('title') ? 'title' : 'sku') . ' as titulo';

        $q = $this->scope($companyId)->select($sel);
        match ($situacao) {
            'ganhando' => $q->where('buybox_winner', true),
            'sem_info' => $q->whereNull('buybox_winner'),
            default => $q->where('buybox_winner', false),
        };

        return $q->orderByDesc(DB::raw($eff))->limit($limit)->get()->map(function ($r) {
            $preco = (float) ($r->preco ?? 0);
            $mkt = isset($r->market_price) ? (float) $r->market_price : null;
            return [
                'id' => $r->id,
                'titulo' => $r->titulo,
                'sku' => $r->sku ?? null,
                'netshoes_sku' => $r->netshoes_sku ?? null,
                'marca' => $r->brand ?? null,
                'preco' => round($preco, 2),
                'market_price' => $mkt,
                'diferenca' => $mkt ? round($preco - $mkt, 2) : null,
                'gap' => ($mkt && $mkt > 0) ? round(($preco - $mkt) / $mkt * 100, 1) : null,
                'seller' => $r->market_seller ?? null,
                'url' => $r->market_url ?? null,
                'ofertas' => $r->market_offers_count ?? null,
                'winner' => $r->buybox_winner ?? null,
                'checked_at' => $r->market_checked_at ?? null,
            ];
        })->all();
    }

    /* =========================== OTIMIZAÇÃO =========================== */

    public function optimizeSummary(int $companyId): array
    {
        if (!Schema::hasTable('products') || !$this->has('market_price')) {
            return [
                'oportunidades' => 0, 'faceis' => 0, 'ajuste_total' => 0,
                'receita_risco' => 0, 'recuperados_30d' => 0, 'ganho_medio' => 0,
            ];
        }

        $eff = $this->effSql();
        $perdendo = $this->withMarket($companyId)->whereRaw("$eff > market_price");

        $oportunidades = (int) (clone $perdendo)->count();
        $faceis = (int) (clone $perdendo)->whereRaw("$eff <= market_price * 1.05")->count();
        $ajuste = (float) (clone $perdendo)->sum(DB::raw("($eff - market_price)"));
        $risco = (float) (clone $perdendo)->sum(DB::raw($eff));

        // Recuperados nos últimos 30 dias: viraram de perdendo -> ganhando.
        $recuperados = 0;
        if (Schema::hasTable('market_snapshots')) {
            try {
                $recuperados = (int) DB::table('market_snapshots as s')
                    ->where('s.company_id', $companyId)
                    ->where('s.captured_at', '>=', now()->subDays(30))
                    ->where('s.buybox_winner', true)
                    ->whereExists(function ($q) {
                        $q->select(DB::raw(1))->from('market_snapshots as p')
                          ->whereColumn('p.product_id', 's.product_id')
                          ->where('p.captured_at', '<', DB::raw('s.captured_at'))
                          ->where('p.buybox_winner', false);
                    })
                    ->distinct()->count('s.product_id');
            } catch (\Throwable $e) {
                $recuperados = 0;
            }
        }

        return [
            'oportunidades' => $oportunidades,
            'faceis' => $faceis,
            'ajuste_total' => round($ajuste, 2),
            'receita_risco' => round($risco, 2),
            'recuperados_30d' => $recuperados,
            'ganho_medio' => $oportunidades > 0 ? round($ajuste / $oportunidades, 2) : 0,
        ];
    }

    /**
     * Oportunidades: produtos perdendo com preço sugerido para reassumir a
     * Buy Box. O sugerido fica logo abaixo do mercado, arredondado para
     * terminar em ,90 — e é marcado como INVIÁVEL se ficar abaixo do piso
     * (custo + margem mínima), para nunca sugerir venda no prejuízo.
     */
    public function opportunities(int $companyId, float $minMarginPct = 10, int $limit = 300): array
    {
        if (!Schema::hasTable('products') || !$this->has('market_price')) {
            return [];
        }

        $eff = $this->effSql();
        $sel = ['id', DB::raw("$eff as preco")];
        foreach (['sku', 'brand', 'market_price', 'market_seller', 'market_url', 'cost_price',
                  'stock_quantity', 'market_offers_count', 'buybox_winner'] as $c) {
            if ($this->has($c)) {
                $sel[] = $c;
            }
        }
        $sel[] = ($this->has('title') ? 'title' : 'sku') . ' as titulo';

        $rows = $this->withMarket($companyId)
            ->select($sel)
            ->whereRaw("$eff > market_price")
            ->orderByRaw("($eff - market_price) DESC")
            ->limit($limit)->get();

        return $rows->map(function ($r) use ($minMarginPct) {
            $preco = (float) ($r->preco ?? 0);
            $mkt = (float) $r->market_price;
            $custo = isset($r->cost_price) ? (float) $r->cost_price : null;

            // Sugerido: logo abaixo do mercado, terminando em ,90
            $sug = floor($mkt) - 0.10;
            if ($sug >= $mkt) {
                $sug = $mkt - 0.10;
            }
            $sug = max(0.10, round($sug, 2));

            $piso = ($custo && $custo > 0) ? round($custo * (1 + $minMarginPct / 100), 2) : null;
            $viavel = $piso === null ? null : ($sug >= $piso);

            return [
                'id' => $r->id,
                'titulo' => $r->titulo,
                'sku' => $r->sku ?? null,
                'marca' => $r->brand ?? null,
                'preco' => round($preco, 2),
                'market_price' => $mkt,
                'sugerido' => $sug,
                'reducao' => round($preco - $sug, 2),
                'gap' => $mkt > 0 ? round(($preco - $mkt) / $mkt * 100, 1) : null,
                'custo' => $custo,
                'piso' => $piso,
                'viavel' => $viavel,
                'seller' => $r->market_seller ?? null,
                'url' => $r->market_url ?? null,
                'estoque' => isset($r->stock_quantity) ? (int) $r->stock_quantity : null,
                'ofertas' => $r->market_offers_count ?? null,
            ];
        })->all();
    }

    /** Otimizações recentes: quedas de preço registradas no histórico. */
    public function recentOptimizations(int $companyId, int $days = 30, int $limit = 200): array
    {
        if (!Schema::hasTable('market_snapshots')) {
            return [];
        }
        try {
            $titleCol = $this->has('title') ? 'p.title' : 'p.sku';
            return DB::table('market_snapshots as s')
                ->join('products as p', 'p.id', '=', 's.product_id')
                ->where('s.company_id', $companyId)
                ->where('s.captured_at', '>=', now()->subDays($days))
                ->selectRaw("s.product_id, $titleCol as titulo, p.sku,
                    MIN(s.our_price) as menor, MAX(s.our_price) as maior,
                    MAX(s.captured_at) as ultima,
                    MAX(CASE WHEN s.buybox_winner = 1 THEN 1 ELSE 0 END) as ja_ganhou")
                ->groupBy('s.product_id', DB::raw($titleCol), 'p.sku')
                ->havingRaw('MAX(s.our_price) > MIN(s.our_price)')
                ->orderByDesc('ultima')->limit($limit)->get()
                ->map(fn ($r) => [
                    'id' => $r->product_id,
                    'titulo' => $r->titulo,
                    'sku' => $r->sku,
                    'de' => round((float) $r->maior, 2),
                    'para' => round((float) $r->menor, 2),
                    'reducao' => round((float) $r->maior - (float) $r->menor, 2),
                    'ganhou' => (bool) $r->ja_ganhou,
                    'quando' => $r->ultima,
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ============================ RELATÓRIO ============================ */

    /**
     * Classificação de concorrência:
     *   alta        -> muitos sellers (>=5) ou estamos >10% acima do mercado
     *   media       -> 2..4 sellers ou 2%..10% acima
     *   normal      -> pouca disputa / estamos competitivos
     *   desconhecida-> sem preço de mercado
     */
    private function competSql(): string
    {
        $eff = $this->effSql();
        $off = $this->has('market_offers_count') ? 'COALESCE(market_offers_count, 0)' : '0';
        return "CASE
            WHEN market_price IS NULL OR market_price = 0 THEN 'desconhecida'
            WHEN $off >= 5 OR ($eff - market_price) / market_price * 100 > 10 THEN 'alta'
            WHEN $off BETWEEN 2 AND 4 OR ($eff - market_price) / market_price * 100 > 2 THEN 'media'
            ELSE 'normal'
        END";
    }

    public function report(int $companyId): array
    {
        if (!Schema::hasTable('products')) {
            return ['por_marca' => [], 'competitividade' => [], 'marcas_risco' => [], 'has_data' => false];
        }

        $comp = $this->competSql();
        $eff = $this->effSql();

        $competitividade = $this->scope($companyId)
            ->selectRaw("($comp) as nivel, COUNT(*) as c")
            ->groupBy('nivel')->pluck('c', 'nivel');

        $porMarca = [];
        $marcasRisco = [];
        if ($this->has('brand')) {
            $porMarca = $this->scope($companyId)
                ->selectRaw("COALESCE(NULLIF(brand,''),'Sem marca') as marca, COUNT(*) as total")
                ->groupBy('marca')->orderByDesc('total')->limit(20)->get()
                ->map(fn ($r) => ['marca' => $r->marca, 'total' => (int) $r->total])->all();

            $wb = $this->has('buybox_winner');
            $marcasRisco = $this->withMarket($companyId)
                ->selectRaw("COALESCE(NULLIF(brand,''),'Sem marca') as marca,
                    COUNT(*) as total,
                    SUM(CASE WHEN $eff > market_price THEN 1 ELSE 0 END) as perdendo,
                    " . ($wb ? "SUM(CASE WHEN buybox_winner = 1 THEN 1 ELSE 0 END)" : "0") . " as ganhando,
                    AVG(($eff - market_price) / market_price * 100) as gap")
                ->groupBy('marca')->orderByDesc('perdendo')->limit(15)->get()
                ->map(fn ($r) => [
                    'marca' => $r->marca,
                    'total' => (int) $r->total,
                    'perdendo' => (int) $r->perdendo,
                    'ganhando' => (int) $r->ganhando,
                    'gap' => round((float) $r->gap, 1),
                ])->all();
        }

        $niveis = [
            ['label' => 'Alta', 'key' => 'alta', 'color' => '#ef4444'],
            ['label' => 'Média', 'key' => 'media', 'color' => '#f59e0b'],
            ['label' => 'Normal', 'key' => 'normal', 'color' => '#10b981'],
            ['label' => 'Desconhecida', 'key' => 'desconhecida', 'color' => '#94a3b8'],
        ];

        return [
            'por_marca' => $porMarca,
            'marcas_risco' => $marcasRisco,
            'competitividade' => array_map(fn ($n) => [
                'label' => $n['label'],
                'value' => (int) ($competitividade[$n['key']] ?? 0),
                'color' => $n['color'],
            ], $niveis),
            'has_data' => !empty($porMarca) || $competitividade->sum() > 0,
        ];
    }
}
