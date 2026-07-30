<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\ReplenishmentEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Reposição Inteligente (/inventory/planning). Só LÊ a tabela
 * `replenishment_plan`, paginada e filtrada no banco — o cálculo roda 1x/dia
 * via `inventory:compute-replenishment` (App\Services\Inventory\ReplenishmentEngine).
 *
 * Antes desta reescrita, a tela mandava 78 mil linhas de uma vez num prop do
 * Inertia (~19MB), travando o navegador, com todo cálculo zerado (ver
 * incidente documentado em ReplenishmentEngine e Order::CONFIRMED_STATUSES).
 */
class ReplenishmentController extends Controller
{
    /** Tabs da tela: cada uma mapeia para um conjunto de status do motor. */
    private const TABS = [
        'acao' => [ReplenishmentEngine::STATUS_RUPTURA, ReplenishmentEngine::STATUS_CRITICO, ReplenishmentEngine::STATUS_REPOR],
        'ruptura' => [ReplenishmentEngine::STATUS_RUPTURA],
        'critico' => [ReplenishmentEngine::STATUS_CRITICO],
        'repor' => [ReplenishmentEngine::STATUS_REPOR],
        'saudavel' => [ReplenishmentEngine::STATUS_SAUDAVEL],
        'excesso' => [ReplenishmentEngine::STATUS_EXCESSO],
        'morto' => [ReplenishmentEngine::STATUS_ESTOQUE_MORTO],
        'descontinuado' => [ReplenishmentEngine::STATUS_DESCONTINUADO],
        'todos' => null,
    ];

    private const SORTABLE = ['priority_score', 'coverage_days', 'suggested_qty', 'revenue_at_risk_30d', 'stock', 'sku'];

    public function index(Request $request, ReplenishmentEngine $engine)
    {
        $companyId = Auth::user()?->company_id;
        $perPage = 50;

        if (!$companyId || !Schema::hasTable('replenishment_plan')) {
            return Inertia::render('Inventory/Replenishment', $this->emptyPayload($request, $engine, $companyId));
        }

        $settings = $engine->settingsFor($companyId);
        $lastComputedAt = DB::table('replenishment_plan')->where('company_id', $companyId)->max('computed_at');

        $tab = $request->get('tab', 'acao');
        $statuses = array_key_exists($tab, self::TABS) ? self::TABS[$tab] : self::TABS['acao'];

        $q = DB::table('replenishment_plan')->where('company_id', $companyId);
        if ($statuses !== null) {
            $q->whereIn('status', $statuses);
        }
        if ($brand = $request->get('brand')) {
            $q->where('brand', $brand);
        }
        if ($abc = $request->get('abc_class')) {
            $q->where('abc_class', $abc);
        }
        if ($search = $request->get('search')) {
            $term = '%' . $search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('sku', 'like', $term)->orWhere('title', 'like', $term);
            });
        }

        $total = (clone $q)->count();
        $page = max(1, (int) $request->get('page', 1));

        $sort = in_array($request->get('sort'), self::SORTABLE, true) ? $request->get('sort') : 'priority_score';
        $direction = $request->get('direction') === 'asc' ? 'asc' : 'desc';

        $rows = $q->orderBy($sort, $direction)
            ->orderBy('id')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r) => $this->presentRow($r, $settings))
            ->all();

        return Inertia::render('Inventory/Replenishment', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'tab' => $tab,
            'tab_counts' => $this->tabCounts($companyId),
            'stats' => $this->stats($companyId),
            'settings' => $this->presentSettings($settings),
            'brands' => $this->brandOptions($companyId),
            'filters' => $request->only(['tab', 'brand', 'abc_class', 'search', 'sort', 'direction']),
            'last_computed_at' => $lastComputedAt,
            'has_data' => DB::table('replenishment_plan')->where('company_id', $companyId)->exists(),
        ]);
    }

    /** Exporta (sem paginação, com os mesmos filtros) para o xlsx client-side — já carregado globalmente no layout. */
    public function export(Request $request, ReplenishmentEngine $engine)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId || !Schema::hasTable('replenishment_plan')) {
            return response()->json(['rows' => []]);
        }

        $settings = $engine->settingsFor($companyId);

        $tab = $request->get('tab', 'acao');
        $statuses = array_key_exists($tab, self::TABS) ? self::TABS[$tab] : self::TABS['acao'];

        $q = DB::table('replenishment_plan')->where('company_id', $companyId);
        if ($statuses !== null) {
            $q->whereIn('status', $statuses);
        }
        if ($brand = $request->get('brand')) {
            $q->where('brand', $brand);
        }
        if ($abc = $request->get('abc_class')) {
            $q->where('abc_class', $abc);
        }
        if ($search = $request->get('search')) {
            $term = '%' . $search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('sku', 'like', $term)->orWhere('title', 'like', $term);
            });
        }

        $rows = $q->orderByDesc('priority_score')
            ->limit(5000)
            ->get()
            ->map(fn ($r) => $this->presentRow($r, $settings))
            ->all();

        return response()->json(['rows' => $rows]);
    }

    /** Salva os parâmetros configuráveis e recalcula na hora (lote síncrono — sem worker de fila confiável no cPanel, CLAUDE.md §6.3). */
    public function updateSettings(Request $request, ReplenishmentEngine $engine)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->withErrors(['company' => 'Empresa não identificada.']);
        }

        $data = $request->validate([
            'weight_v7' => 'required|numeric|min:0|max:1',
            'weight_v30' => 'required|numeric|min:0|max:1',
            'weight_v90' => 'required|numeric|min:0|max:1',
            'target_coverage_days' => 'required|integer|min:1|max:365',
            'safety_days' => 'required|integer|min:0|max:180',
            'service_level_z' => 'required|numeric|min:0|max:4',
            'excess_threshold_days' => 'required|integer|min:1|max:730',
            'dead_stock_days' => 'required|integer|min:1|max:730',
        ]);

        set_time_limit(0);

        DB::table('replenishment_settings')->updateOrInsert(
            ['company_id' => $companyId],
            array_merge($data, ['updated_at' => now()])
        );

        $engine->computeCompany($companyId);

        return back()->with('success', 'Parâmetros salvos e plano recalculado.');
    }

    /** Recalcula sob demanda (botão "Recalcular agora" na tela), mesmo padrão síncrono. */
    public function recompute(ReplenishmentEngine $engine)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->withErrors(['company' => 'Empresa não identificada.']);
        }

        set_time_limit(0);
        $count = $engine->computeCompany($companyId);

        return back()->with('success', "{$count} SKUs recalculados.");
    }

    private function presentRow(object $r, object $settings): array
    {
        return [
            'product_id' => $r->product_id,
            'sku' => $r->sku,
            'title' => $r->title,
            'brand' => $r->brand,
            'stock' => (int) $r->stock,
            'cost_price' => (float) $r->cost_price,
            'sale_price' => (float) $r->sale_price,
            'velocity_weighted' => (float) $r->velocity_weighted,
            'lead_time_days' => (int) $r->lead_time_days,
            'safety_stock' => (float) $r->safety_stock,
            'reorder_point' => (float) $r->reorder_point,
            'coverage_days' => $r->coverage_days !== null ? (float) $r->coverage_days : null,
            'suggested_qty' => (int) $r->suggested_qty,
            'status' => $r->status,
            'abc_class' => $r->abc_class,
            'revenue_30d' => (float) $r->revenue_30d,
            'revenue_at_risk_30d' => (float) $r->revenue_at_risk_30d,
            'immobilized_value' => (float) $r->immobilized_value,
            'priority_score' => (float) $r->priority_score,
            'reason' => $this->reasonFor($r, $settings),
        ];
    }

    /** Coluna "motivo" em linguagem humana — só formatação, nenhum número novo é calculado aqui. */
    private function reasonFor(object $r, object $settings): string
    {
        $velocity = number_format((float) $r->velocity_weighted, 1, ',', '.');

        return match ($r->status) {
            ReplenishmentEngine::STATUS_RUPTURA =>
                "Sem estoque e vende {$velocity} un/dia — compra urgente.",
            ReplenishmentEngine::STATUS_DESCONTINUADO =>
                "Sem estoque e sem venda nos últimos {$settings->dead_stock_days} dias — fora do radar de compra.",
            ReplenishmentEngine::STATUS_ESTOQUE_MORTO =>
                "Tem estoque mas nenhuma venda nos últimos {$settings->dead_stock_days} dias — capital parado, considere liquidar.",
            ReplenishmentEngine::STATUS_EXCESSO =>
                "Cobre " . number_format((float) $r->coverage_days, 0, ',', '.') . " dias de venda, muito acima do alvo — não comprar.",
            ReplenishmentEngine::STATUS_CRITICO =>
                "Vende {$velocity} un/dia, cobre " . number_format((float) $r->coverage_days, 0, ',', '.')
                    . " dias, lead time {$r->lead_time_days} dias — acaba antes de chegar mercadoria nova, comprar {$r->suggested_qty} un.",
            ReplenishmentEngine::STATUS_REPOR =>
                "Vende {$velocity} un/dia, cobre " . number_format((float) $r->coverage_days, 0, ',', '.')
                    . " dias, lead time {$r->lead_time_days} dias — comprar {$r->suggested_qty} un.",
            default =>
                "Vende {$velocity} un/dia, cobre " . ($r->coverage_days !== null ? number_format((float) $r->coverage_days, 0, ',', '.') . ' dias' : 'período indefinido') . " — dentro do esperado.",
        };
    }

    private function tabCounts(int $companyId): array
    {
        $counts = DB::table('replenishment_plan')
            ->where('company_id', $companyId)
            ->select('status', DB::raw('count(*) as c'))
            ->groupBy('status')
            ->pluck('c', 'status');

        $result = [];
        foreach (self::TABS as $tab => $statuses) {
            $result[$tab] = $statuses === null
                ? (int) $counts->sum()
                : (int) collect($statuses)->sum(fn ($s) => (int) ($counts[$s] ?? 0));
        }
        return $result;
    }

    private function stats(int $companyId): array
    {
        $base = DB::table('replenishment_plan')->where('company_id', $companyId);

        $buyNowInvestment = (clone $base)
            ->whereIn('status', [ReplenishmentEngine::STATUS_RUPTURA, ReplenishmentEngine::STATUS_CRITICO])
            ->selectRaw('COALESCE(SUM(suggested_qty * cost_price), 0) as v')->value('v');

        $restockInvestment = (clone $base)
            ->where('status', ReplenishmentEngine::STATUS_REPOR)
            ->selectRaw('COALESCE(SUM(suggested_qty * cost_price), 0) as v')->value('v');

        $revenueAtRisk = (clone $base)->sum('revenue_at_risk_30d');
        $immobilizedExcess = (clone $base)->where('status', ReplenishmentEngine::STATUS_EXCESSO)->sum('immobilized_value');
        $immobilizedDead = (clone $base)->where('status', ReplenishmentEngine::STATUS_ESTOQUE_MORTO)->sum('immobilized_value');
        $avgCoverage = (clone $base)->whereNotNull('coverage_days')->avg('coverage_days');

        return [
            'investment_buy_now' => (float) $buyNowInvestment,
            'investment_restock' => (float) $restockInvestment,
            'revenue_at_risk_30d' => (float) $revenueAtRisk,
            'immobilized_excess' => (float) $immobilizedExcess,
            'immobilized_dead_stock' => (float) $immobilizedDead,
            'avg_coverage_days' => $avgCoverage !== null ? round((float) $avgCoverage, 1) : null,
            'sku_count' => (clone $base)->count(),
        ];
    }

    private function brandOptions(int $companyId): array
    {
        return DB::table('replenishment_plan')
            ->where('company_id', $companyId)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->limit(300)
            ->pluck('brand')
            ->all();
    }

    private function presentSettings(object $settings): array
    {
        return [
            'weight_v7' => (float) $settings->weight_v7,
            'weight_v30' => (float) $settings->weight_v30,
            'weight_v90' => (float) $settings->weight_v90,
            'target_coverage_days' => (int) $settings->target_coverage_days,
            'safety_days' => (int) $settings->safety_days,
            'service_level_z' => (float) $settings->service_level_z,
            'excess_threshold_days' => (int) $settings->excess_threshold_days,
            'dead_stock_days' => (int) $settings->dead_stock_days,
        ];
    }

    private function emptyPayload(Request $request, ReplenishmentEngine $engine, ?int $companyId): array
    {
        $settings = $companyId ? $engine->settingsFor($companyId) : (object) [
            'weight_v7' => 0.5, 'weight_v30' => 0.3, 'weight_v90' => 0.2,
            'target_coverage_days' => 30, 'safety_days' => 7, 'service_level_z' => 1.65,
            'excess_threshold_days' => 120, 'dead_stock_days' => 90,
        ];

        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 50,
            'last_page' => 1,
            'tab' => $request->get('tab', 'acao'),
            'tab_counts' => array_fill_keys(array_keys(self::TABS), 0),
            'stats' => [
                'investment_buy_now' => 0.0, 'investment_restock' => 0.0, 'revenue_at_risk_30d' => 0.0,
                'immobilized_excess' => 0.0, 'immobilized_dead_stock' => 0.0, 'avg_coverage_days' => null, 'sku_count' => 0,
            ],
            'settings' => $this->presentSettings($settings),
            'brands' => [],
            'filters' => $request->only(['tab', 'brand', 'abc_class', 'search', 'sort', 'direction']),
            'last_computed_at' => null,
            'has_data' => false,
        ];
    }
}
