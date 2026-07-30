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
    public function __construct(
        private PricingEngine $pricingEngine,
        private ChannelConfigService $channelConfig
    ) {
    }

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

    /** Margens mínimas por marca (mesma fonte usada pelo RepricingEngine). */
    private function brandMargins(int $companyId): array
    {
        if (!Schema::hasTable('brand_margins')) {
            return [];
        }
        try {
            return DB::table('brand_margins')->where('company_id', $companyId)
                ->pluck('min_margin_pct', 'brand')
                ->mapWithKeys(fn ($v, $k) => [mb_strtolower(trim((string) $k)) => (float) $v])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Otimizar por canal: para cada canal ativo da empresa, verifica a saúde
     * de margem de todo SKU vinculado àquele canal (mesmo critério de vínculo
     * do Cálculo Promo: channel_prices[col], ou netshoes_price no caso da
     * Netshoes) e, onde há dado de mercado real (hoje só a Netshoes, via
     * Buy Box), sinaliza oportunidade de reduzir preço para reassumir a
     * disputa — mas o preço sugerido NUNCA fica abaixo do piso daquele canal
     * (custo + encargos do canal + margem mínima da marca/global), a mesma
     * trava usada pelo RepricingEngine.
     *
     * Diferente do repricing automático (que só mexe quando há competidor
     * conhecido), aqui também aparece quem está com margem doente MESMO SEM
     * dado de concorrência — porque o custo subiu, ou o preço nunca foi
     * ajustado depois que o canal mudou de comissão, por exemplo.
     */
    public function opportunitiesByChannel(int $companyId, float $minMarginPct = 10, int $limitPerChannel = 300): array
    {
        if (!Schema::hasTable('products')) {
            return ['channels' => []];
        }

        $hasChannelPrices = $this->has('channel_prices');
        $hasNetshoesPrice = $this->has('netshoes_price');
        $margins = $this->brandMargins($companyId);

        $config = $this->channelConfig->forCompany($companyId);
        $imposto = (float) ($config['imposto'] ?? 0);
        $channels = collect($config['channels'] ?? [])->filter(fn ($c) => $c['active'] ?? true)->values();

        $select = ['id', 'sku', 'cost_price', 'stock_quantity'];
        foreach (['title', 'brand', 'sale_price', 'channel_prices', 'netshoes_price',
                  'market_price', 'market_seller', 'market_url', 'buybox_winner'] as $c) {
            if ($this->has($c)) {
                $select[] = $c;
            }
        }

        $rows = $this->scope($companyId)->select($select)->get();

        $out = [];
        foreach ($channels as $ch) {
            $chId = (string) ($ch['id'] ?? '');
            $label = (string) ($ch['label'] ?? $chId);
            $comissao = (float) ($ch['comissao'] ?? 0);
            $encargos = $comissao + $imposto;
            $col = $ch['col'] ?? null;
            if (!$col && $chId === 'centauro') $col = 'Centauro';
            $isSite = $chId === 'site';
            $isNetshoes = $chId === 'netshoes';

            $items = [];
            foreach ($rows as $r) {
                $sku = (string) ($r->sku ?? '');
                if ($sku === '') continue;

                $cp = [];
                if ($hasChannelPrices) {
                    $decoded = json_decode($r->channel_prices ?? '', true);
                    if (is_array($decoded)) $cp = $decoded;
                }

                $preco = null;
                if ($col && isset($cp[$col]) && (float) $cp[$col] > 0) {
                    $preco = (float) $cp[$col];
                }
                if ($preco === null && $isNetshoes && $hasNetshoesPrice && (float) ($r->netshoes_price ?? 0) > 0) {
                    $preco = (float) $r->netshoes_price;
                }
                if ($isSite && $preco === null && (float) ($r->sale_price ?? 0) > 0) {
                    $preco = (float) $r->sale_price;
                }
                if ($preco === null) continue; // sem vínculo com este canal — não aparece

                $custo = isset($r->cost_price) ? (float) $r->cost_price : null;
                $marca = $r->brand ?? null;
                $margem = ($marca && isset($margins[mb_strtolower(trim($marca))]))
                    ? $margins[mb_strtolower(trim($marca))]
                    : $minMarginPct;

                $piso = ($custo && $custo > 0) ? $this->pricingEngine->floorPrice($custo, $encargos, $margem) : null;
                $margemAtual = ($custo !== null) ? $this->pricingEngine->netMarginPct($preco, $custo, $encargos) : null;
                $saudavel = $piso === null ? null : ($preco >= $piso);

                $item = [
                    'id' => $r->id,
                    'sku' => $sku,
                    'titulo' => $r->title ?? $sku,
                    'marca' => $marca,
                    'preco' => round($preco, 2),
                    'custo' => $custo,
                    'encargos_pct' => round($encargos, 2),
                    'margem_minima_pct' => $margem,
                    'piso' => $piso !== null ? round($piso, 2) : null,
                    'margem_atual_pct' => $margemAtual,
                    'saudavel' => $saudavel,
                    'estoque' => isset($r->stock_quantity) ? (int) $r->stock_quantity : null,
                    'market_price' => null,
                    'gap' => null,
                    'perdendo_buybox' => null,
                    'seller' => null,
                    'sugerido' => null,
                ];

                // Buy Box: só a Netshoes tem preço de mercado coletado hoje (ver CLAUDE.md §5.2/§7).
                if ($isNetshoes && isset($r->market_price) && (float) $r->market_price > 0) {
                    $mkt = (float) $r->market_price;
                    $perdendo = $preco > $mkt;
                    $item['market_price'] = $mkt;
                    $item['gap'] = round(($preco - $mkt) / $mkt * 100, 1);
                    $item['perdendo_buybox'] = $perdendo;
                    $item['seller'] = $r->market_seller ?? null;

                    if ($perdendo) {
                        $sug = floor($mkt) - 0.10;
                        if ($sug >= $mkt) $sug = $mkt - 0.10;
                        $sug = max(0.10, round($sug, 2));
                        // Nunca sugere abaixo do piso do canal — mesma trava do RepricingEngine.
                        if ($piso !== null && $sug < $piso) $sug = round($piso, 2);
                        $item['sugerido'] = $sug;
                    }
                }

                $items[] = $item;
            }

            $total = count($items);
            $abaixoPiso = count(array_filter($items, fn ($i) => $i['saudavel'] === false));
            $perdendoBB = count(array_filter($items, fn ($i) => $i['perdendo_buybox'] === true));

            // Prioridade: margem doente primeiro, depois perdendo Buy Box, depois o resto.
            usort($items, function ($a, $b) {
                $rank = fn ($i) => $i['saudavel'] === false ? 0 : ($i['perdendo_buybox'] === true ? 1 : 2);
                return $rank($a) <=> $rank($b);
            });

            $out[] = [
                'key' => $chId,
                'label' => $label,
                'comissao' => $comissao,
                'encargos_pct' => round($encargos, 2),
                'has_market_data' => $isNetshoes,
                'stats' => ['total' => $total, 'abaixo_piso' => $abaixoPiso, 'perdendo_buybox' => $perdendoBB],
                'items' => array_slice($items, 0, $limitPerChannel),
            ];
        }

        return ['channels' => $out];
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
        // NÃO usamos offerCount: no HTML da Netshoes ele conta FAIXAS DE PREÇO
        // (à vista/parcelado), não número de sellers. A classificação usa
        // apenas a distância do nosso preço para o preço de mercado.
        return "CASE
            WHEN market_price IS NULL OR market_price = 0 THEN 'desconhecida'
            WHEN ($eff - market_price) / market_price * 100 > 10 THEN 'alta'
            WHEN ($eff - market_price) / market_price * 100 > 2 THEN 'media'
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
