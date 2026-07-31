<?php

namespace App\Services\Marketing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motor de oportunidades de marketing.
 *
 * Regra de ouro: NUNCA recalcula velocidade, cobertura ou faturamento do
 * zero — lê o que já está pronto em `replenishment_plan` (motor de
 * Reposição Inteligente, recalculado 1x/dia) e em `products.launched_at`.
 * Duplicar esse cálculo aqui seria o mesmo erro que gerou o bug de
 * `whereIn` estourando placeholders no motor original — dois lugares
 * calculando a mesma coisa sempre divergem cedo ou tarde.
 *
 * Quatro categorias de oportunidade, cada uma mapeada para uma ação real de
 * marketing:
 *
 *  - LANÇAMENTO: produto lançado recentemente (products.launched_at) —
 *    precisa de visibilidade/divulgação antes de ganhar histórico de venda.
 *  - MAIS_VENDIDO: curva ABC classe A (replenishment_plan.abc_class) —
 *    já vende bem, reforçar mídia amplia o que já funciona.
 *  - LIQUIDAR: status excesso/estoque_morto (replenishment_plan.status) —
 *    capital parado, precisa de campanha de desconto pra girar.
 *  - PERDENDO_BUYBOX: products.buybox_winner = false, priorizado por
 *    faturamento (replenishment_plan.revenue_30d) — perder Buy Box num
 *    produto que vende 300/mês não é o mesmo que num que vende 2 (roadmap
 *    "Curva ABC × Buy Box"). Não depende de scraper nem API: só lê o que
 *    já foi importado do relatório de Buy Box / Seller Center.
 */
class MarketingOpportunityService
{
    public const LANCAMENTO = 'lancamento';
    public const MAIS_VENDIDO = 'mais_vendido';
    public const LIQUIDAR = 'liquidar';
    public const PERDENDO_BUYBOX = 'perdendo_buybox';

    private const DEFAULT_LAUNCH_WINDOW_DAYS = 60;
    private const BUYBOX_SCAN_LIMIT = 300;

    /** Resumo com as quatro categorias, já prontas pro dashboard. */
    public function opportunities(int $companyId, int $limitPerType = 20): array
    {
        return [
            self::LANCAMENTO => $this->launches($companyId, $limitPerType),
            self::MAIS_VENDIDO => $this->bestSellers($companyId, $limitPerType),
            self::LIQUIDAR => $this->liquidationCandidates($companyId, $limitPerType),
            self::PERDENDO_BUYBOX => $this->buyboxLosses($companyId, $limitPerType),
        ];
    }

    /** Lançamentos: produtos com launched_at dentro da janela (padrão 60 dias). */
    public function launches(int $companyId, int $limit = 20, int $withinDays = self::DEFAULT_LAUNCH_WINDOW_DAYS): array
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'launched_at')) {
            return [];
        }

        $since = Carbon::now()->subDays($withinDays)->startOfDay();

        $products = DB::table('products')
            ->where('company_id', $companyId)
            ->whereNotNull('launched_at')
            ->where('launched_at', '>=', $since)
            ->orderByDesc('launched_at')
            ->limit($limit)
            ->select(['id', 'sku', 'title', 'brand', 'launched_at', 'sale_price', 'stock_quantity'])
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $velocities = Schema::hasTable('replenishment_plan')
            ? DB::table('replenishment_plan')
                ->where('company_id', $companyId)
                ->whereIn('product_id', $products->pluck('id'))
                ->pluck('velocity_weighted', 'product_id')
            : collect();

        return $products->map(function ($p) use ($velocities) {
            $launchedAt = Carbon::parse($p->launched_at);
            $velocity = (float) ($velocities[$p->id] ?? 0);

            return [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $p->brand,
                'launched_at' => $launchedAt->toDateString(),
                'sale_price' => (float) $p->sale_price,
                'stock' => (int) $p->stock_quantity,
                'velocity' => round($velocity, 2),
                'reason' => sprintf(
                    'Lançado %s — %s. Ideal pra puxar tráfego antes de virar "mais um produto".',
                    $launchedAt->diffForHumans(),
                    $velocity > 0 ? 'já vendendo ' . number_format($velocity, 1, ',', '.') . ' un/dia' : 'ainda sem histórico de venda'
                ),
            ];
        })->all();
    }

    /** Mais vendidos: curva ABC classe A, ordenado pelo faturamento dos últimos 30 dias. */
    public function bestSellers(int $companyId, int $limit = 20): array
    {
        if (!Schema::hasTable('replenishment_plan')) {
            return [];
        }

        return DB::table('replenishment_plan')
            ->where('company_id', $companyId)
            ->where('abc_class', 'A')
            ->orderByDesc('revenue_30d')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'product_id' => $p->product_id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $p->brand,
                'revenue_30d' => (float) $p->revenue_30d,
                'velocity' => (float) $p->velocity_weighted,
                'stock' => (int) $p->stock,
                'coverage_days' => $p->coverage_days !== null ? (float) $p->coverage_days : null,
                'reason' => sprintf(
                    'Curva A — vende %s un/dia, faturou R$ %s em 30 dias. Reforçar mídia amplia o que já funciona.',
                    number_format((float) $p->velocity_weighted, 1, ',', '.'),
                    number_format((float) $p->revenue_30d, 2, ',', '.')
                ),
            ])->all();
    }

    /** Liquidar: excesso/estoque morto do motor de reposição, ordenado pelo capital parado. */
    public function liquidationCandidates(int $companyId, int $limit = 20): array
    {
        if (!Schema::hasTable('replenishment_plan')) {
            return [];
        }

        return DB::table('replenishment_plan')
            ->where('company_id', $companyId)
            ->whereIn('status', ['excesso', 'estoque_morto'])
            ->orderByDesc('immobilized_value')
            ->limit($limit)
            ->get()
            ->map(fn ($p) => [
                'product_id' => $p->product_id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $p->brand,
                'status' => $p->status,
                'stock' => (int) $p->stock,
                'immobilized_value' => (float) $p->immobilized_value,
                'coverage_days' => $p->coverage_days !== null ? (float) $p->coverage_days : null,
                'reason' => $p->status === 'estoque_morto'
                    ? sprintf('Sem venda recente — R$ %s parados em estoque. Campanha de liquidação libera capital.', number_format((float) $p->immobilized_value, 2, ',', '.'))
                    : sprintf('Cobertura de %s dias, muito acima do normal — desconto agressivo acelera o giro antes de virar estoque morto.', $p->coverage_days !== null ? number_format((float) $p->coverage_days, 0, ',', '.') : '?'),
            ])->all();
    }

    /**
     * Perdendo Buy Box: produtos com `buybox_winner = false`, priorizados por
     * faturamento (não por contagem — ver §5.2 do CLAUDE.md sobre offerCount).
     * Só considera produtos com `monitored = true` quando a coluna existe.
     */
    public function buyboxLosses(int $companyId, int $limit = 20): array
    {
        if (!Schema::hasTable('products') || !Schema::hasColumn('products', 'buybox_winner')) {
            return [];
        }

        $query = DB::table('products')
            ->where('company_id', $companyId)
            ->where('buybox_winner', false);

        if (Schema::hasColumn('products', 'monitored')) {
            $query->where('monitored', true);
        }

        $products = $query
            ->limit(self::BUYBOX_SCAN_LIMIT)
            ->select(['id', 'sku', 'title', 'brand', 'sale_price', 'market_price', 'market_seller'])
            ->get();

        if ($products->isEmpty()) {
            return [];
        }

        $plan = Schema::hasTable('replenishment_plan')
            ? DB::table('replenishment_plan')
                ->where('company_id', $companyId)
                ->whereIn('product_id', $products->pluck('id'))
                ->get(['product_id', 'revenue_30d', 'abc_class'])
                ->keyBy('product_id')
            : collect();

        return $products->map(function ($p) use ($plan) {
            $row = $plan[$p->id] ?? null;
            $revenue = $row ? (float) $row->revenue_30d : 0.0;
            $abc = $row->abc_class ?? null;

            return [
                'product_id' => $p->id,
                'sku' => $p->sku,
                'title' => $p->title,
                'brand' => $p->brand,
                'sale_price' => (float) $p->sale_price,
                'market_price' => $p->market_price !== null ? (float) $p->market_price : null,
                'market_seller' => $p->market_seller,
                'revenue_30d' => $revenue,
                'abc_class' => $abc,
                'reason' => sprintf(
                    'Perdendo Buy Box%s%s. %s',
                    $p->market_seller ? ' para ' . $p->market_seller : '',
                    $abc ? ' — curva ' . $abc : '',
                    $revenue > 0
                        ? 'Faturou R$ ' . number_format($revenue, 2, ',', '.') . ' em 30 dias — recuperar aqui evita perda direta de receita.'
                        : 'Ainda sem dado de faturamento recente.'
                ),
            ];
        })
            ->sortByDesc('revenue_30d')
            ->take($limit)
            ->values()
            ->all();
    }
}
