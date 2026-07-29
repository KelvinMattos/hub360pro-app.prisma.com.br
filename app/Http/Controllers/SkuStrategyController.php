<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Tela de Segmentação: só LÊ a tabela `sku_strategy`, nunca calcula na hora
 * do request — a classificação roda 1x/dia via `sku:classify-strategy`
 * (App\Services\SkuStrategyClassifier).
 */
class SkuStrategyController extends Controller
{
    private const PRICING_ROLES = ['estrela', 'alavanca', 'nicho', 'reavaliar', 'sem_dado'];
    private const LIFECYCLE_STAGES = ['novo', 'crescimento', 'estavel', 'declinio', 'sem_giro'];
    private const COMPETITIVE_POSITIONS = ['vendendo', 'perdendo', 'alerta', 'desconhecido'];

    public function index(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        $perPage = 50;

        if (!$companyId || !Schema::hasTable('sku_strategy')) {
            return Inertia::render('Segmentation/Index', $this->emptyPayload($request));
        }

        $lastComputedAt = DB::table('sku_strategy')->where('company_id', $companyId)->max('computed_at');

        $q = DB::table('sku_strategy as s')
            ->join('products as p', 'p.id', '=', 's.product_id')
            ->where('s.company_id', $companyId);

        if ($role = $request->get('pricing_role')) {
            $q->where('s.pricing_role', $role);
        }
        if ($lifecycle = $request->get('lifecycle_stage')) {
            $q->where('s.lifecycle_stage', $lifecycle);
        }
        if ($position = $request->get('competitive_position')) {
            $q->where('s.competitive_position', $position);
        }
        if ($search = $request->get('search')) {
            $term = '%' . $search . '%';
            $q->where(function ($w) use ($term) {
                $w->where('p.title', 'like', $term)->orWhere('p.sku', 'like', $term);
            });
        }

        $total = (clone $q)->count();
        $page = max(1, (int) $request->get('page', 1));

        $rows = $q->select(
            's.*', 'p.title as product_title', 'p.sku as product_sku', 'p.brand as product_brand'
        )
            ->orderByDesc('s.computed_at')
            ->orderBy('p.title')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn ($r) => [
                'product_id' => $r->product_id,
                'title' => $r->product_title,
                'sku' => $r->product_sku ?? $r->sku,
                'brand' => $r->product_brand,
                'pricing_role' => $r->pricing_role,
                'margin_pct' => $r->margin_pct !== null ? (float) $r->margin_pct : null,
                'volume_30d' => (int) $r->volume_30d,
                'lifecycle_stage' => $r->lifecycle_stage,
                'trend_30_90_pct' => $r->trend_30_90_pct !== null ? (float) $r->trend_30_90_pct : null,
                'stock_health_index' => $r->stock_health_index !== null ? (int) $r->stock_health_index : null,
                'stock_coverage_days' => $r->stock_coverage_days !== null ? (float) $r->stock_coverage_days : null,
                'competitive_position' => $r->competitive_position,
                'market_gap_pct' => $r->market_gap_pct !== null ? (float) $r->market_gap_pct : null,
                'buybox_distance_pct' => $r->buybox_distance_pct !== null ? (float) $r->buybox_distance_pct : null,
                'computed_at' => $r->computed_at,
            ])->all();

        $summary = [
            'pricing_role' => $this->countsBy($companyId, 'pricing_role', self::PRICING_ROLES),
            'lifecycle_stage' => $this->countsBy($companyId, 'lifecycle_stage', self::LIFECYCLE_STAGES),
            'competitive_position' => $this->countsBy($companyId, 'competitive_position', self::COMPETITIVE_POSITIONS),
        ];

        return Inertia::render('Segmentation/Index', [
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'summary' => $summary,
            'last_computed_at' => $lastComputedAt,
            'filters' => $request->only(['pricing_role', 'lifecycle_stage', 'competitive_position', 'search']),
            'has_data' => DB::table('sku_strategy')->where('company_id', $companyId)->exists(),
        ]);
    }

    private function countsBy(int $companyId, string $column, array $knownValues): array
    {
        $counts = DB::table('sku_strategy')
            ->where('company_id', $companyId)
            ->select($column, DB::raw('count(*) as c'))
            ->groupBy($column)
            ->pluck('c', $column);

        $result = [];
        foreach ($knownValues as $value) {
            $result[$value] = (int) ($counts[$value] ?? 0);
        }
        return $result;
    }

    private function emptyPayload(Request $request): array
    {
        return [
            'rows' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 50,
            'last_page' => 1,
            'summary' => [
                'pricing_role' => array_fill_keys(self::PRICING_ROLES, 0),
                'lifecycle_stage' => array_fill_keys(self::LIFECYCLE_STAGES, 0),
                'competitive_position' => array_fill_keys(self::COMPETITIVE_POSITIONS, 0),
            ],
            'last_computed_at' => null,
            'filters' => $request->only(['pricing_role', 'lifecycle_stage', 'competitive_position', 'search']),
            'has_data' => false,
        ];
    }
}
