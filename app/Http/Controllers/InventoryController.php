<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\InventoryIntelligenceService;
use Illuminate\Support\Facades\Auth;

class InventoryController extends Controller
{
    protected $service;

    public function __construct(InventoryIntelligenceService $service)
    {
        $this->service = $service;
    }

    public function planning(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id)
            return redirect()->route('dashboard');

        $inventoryData = $this->service->getAggregatedInventoryStats($user->company_id);
        
        $stats = [
            'total_investment' => $inventoryData->sum('investment_needed'),
            'critical_count' => $inventoryData->where('status', 'critical')->count(),
            'immobilized' => $inventoryData->sum('immobilized_value'),
            'total_revenue' => $inventoryData->sum('revenue_30d'),
            'lost_money' => $inventoryData->sum('lost_revenue')
        ];

        return \Inertia\Inertia::render('Inventory/Replenishment', compact('inventoryData', 'stats'));
    }

    public function warRoom()
    {
        // Placeholder funcional para a War Room
        return \Inertia\Inertia::render('Inventory/Planning', [
            'inventoryData' => collect([]),
            'stats' => ['total_investment' => 0, 'critical_count' => 0, 'immobilized' => 0, 'total_revenue' => 0, 'lost_money' => 0]
        ]);
    }

    public function calculator()
    {
        return redirect()->route('dashboard')->with('info', 'Calculadora em breve');
    }

    /**
     * Aging de Estoque — distribui os produtos com estoque por faixa de idade
     * (com base na Data de Lançamento importada do Magazord) e destaca o valor
     * imobilizado, para apoiar decisões de promoção/liquidação.
     */
    public function aging(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('dashboard');
        }

        $cols = ['sku', 'title', 'stock_quantity', 'cost_price', 'sale_price'];
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'brand')) $cols[] = 'brand';
        if (\Illuminate\Support\Facades\Schema::hasColumn('products', 'launched_at')) $cols[] = 'launched_at';

        $products = \App\Models\Product::query()
            ->select($cols)
            ->where('stock_quantity', '>', 0)
            ->get();

        $order = ['Menos de 6 meses', 'Mais de 6 meses', '1 ano', '1 ano e meio', '2 anos', '+2 anos', 'Sem data'];
        $buckets = [];
        foreach ($order as $label) {
            $buckets[$label] = ['label' => $label, 'count' => 0, 'units' => 0, 'cost_value' => 0.0];
        }

        $now = now();
        $agedThreshold = ['1 ano', '1 ano e meio', '2 anos', '+2 anos']; // >= 12 meses
        $oldest = [];
        $totalUnits = 0; $totalCost = 0.0; $agedCost = 0.0;

        foreach ($products as $p) {
            $units = (int) $p->stock_quantity;
            $stockValue = (float) $p->cost_price * $units;
            $bucket = 'Sem data';
            $ageMonths = null;

            if ($p->launched_at) {
                $ageMonths = (int) abs($p->launched_at->diffInMonths($now));
                $bucket = $this->ageBucket($ageMonths);
            }

            $buckets[$bucket]['count']++;
            $buckets[$bucket]['units'] += $units;
            $buckets[$bucket]['cost_value'] += $stockValue;

            $totalUnits += $units;
            $totalCost += $stockValue;
            if (in_array($bucket, $agedThreshold, true)) {
                $agedCost += $stockValue;
            }

            if ($p->launched_at) {
                $oldest[] = [
                    'sku' => $p->sku,
                    'title' => $p->title,
                    'brand' => $p->brand ?? null,
                    'launched_at' => $p->launched_at->format('Y-m-d'),
                    'age_months' => $ageMonths,
                    'bucket' => $bucket,
                    'stock' => $units,
                    'cost_price' => (float) $p->cost_price,
                    'sale_price' => (float) $p->sale_price,
                    'stock_value' => $stockValue,
                ];
            }
        }

        usort($oldest, fn ($a, $b) => strcmp($a['launched_at'], $b['launched_at']));
        $oldest = array_slice($oldest, 0, 60);

        $stats = [
            'total_skus' => $products->count(),
            'total_units' => $totalUnits,
            'total_cost_value' => $totalCost,
            'aged_cost_value' => $agedCost,
            'aged_pct' => $totalCost > 0 ? round($agedCost / $totalCost * 100, 1) : 0,
            'without_date' => $buckets['Sem data']['count'],
        ];

        return \Inertia\Inertia::render('Inventory/Aging', [
            'buckets' => array_values($buckets),
            'stats' => $stats,
            'oldest' => $oldest,
        ]);
    }

    /** Faixa de idade (meses desde o lançamento) — alinhada ao Cálculo Promo. */
    private function ageBucket(int $months): string
    {
        return match (true) {
            $months < 6 => 'Menos de 6 meses',
            $months < 12 => 'Mais de 6 meses',
            $months < 18 => '1 ano',
            $months < 24 => '1 ano e meio',
            $months < 30 => '2 anos',
            default => '+2 anos',
        };
    }
}