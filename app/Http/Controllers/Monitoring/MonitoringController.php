<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\MarketMonitorService;
use App\Services\MarketOptimizerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Monitoramento de Preços (competitividade estilo Hooklab).
 *   - Dashboard: indicadores, distribuição de status, evolução, por canal.
 *   - Produtos: lista monitorada com filtros/ordenação/busca + edição do
 *     preço de mercado (feed manual enquanto não há coleta automática).
 */
class MonitoringController extends Controller
{
    public function __construct(
        private MarketMonitorService $monitor,
        private MarketOptimizerService $optimizer
    ) {
    }

    private function companyId(): ?int
    {
        $u = Auth::user();
        return $u ? $u->company_id : null;
    }

    public function dashboard(Request $request)
    {
        $companyId = $this->companyId();
        if (!$companyId) {
            return redirect()->route('login');
        }
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [1, 7, 30, 90, 180], true) ? $days : 30;

        return Inertia::render('Monitoring/Dashboard', array_merge(
            ['days' => $days, 'buybox' => $this->optimizer->buybox($companyId)],
            $this->monitor->summary($companyId, $days)
        ));
    }

    public function products(Request $request)
    {
        $companyId = $this->companyId();
        if (!$companyId) {
            return redirect()->route('login');
        }

        $filters = [
            'filter' => $request->query('filter', 'all'),
            'marketplace' => $request->query('marketplace'),
            'search' => trim((string) $request->query('search', '')),
            'sort' => $request->query('sort', 'titulo'),
            'dir' => $request->query('dir', 'asc'),
            'page' => (int) $request->query('page', 1),
        ];

        $marketplaces = [];
        if (Schema::hasColumn('products', 'selling_channel')) {
            $q = DB::table('products');
            if (Schema::hasColumn('products', 'company_id')) {
                $q->where('company_id', $companyId);
            }
            $marketplaces = $q->whereNotNull('selling_channel')->where('selling_channel', '!=', '')
                ->distinct()->orderBy('selling_channel')->pluck('selling_channel')->all();
        }

        return Inertia::render('Monitoring/Products', [
            'result' => $this->monitor->products($companyId, $filters),
            'filters' => $filters,
            'marketplaces' => $marketplaces,
        ]);
    }

    /** Define/atualiza o preço de mercado de um produto (feed manual). */
    public function setMarket(Request $request, int $product)
    {
        $companyId = $this->companyId();
        if (!$companyId) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'market_price' => ['nullable', 'numeric', 'min:0'],
            'market_seller' => ['nullable', 'string', 'max:180'],
        ]);

        $q = DB::table('products')->where('id', $product);
        if (Schema::hasColumn('products', 'company_id')) {
            $q->where('company_id', $companyId);
        }

        $payload = [];
        if (Schema::hasColumn('products', 'market_price')) {
            $payload['market_price'] = $data['market_price'] ?? null;
        }
        if (Schema::hasColumn('products', 'market_seller')) {
            $payload['market_seller'] = $data['market_seller'] ?? null;
        }
        if (Schema::hasColumn('products', 'market_source')) {
            $payload['market_source'] = 'manual';
        }
        if (Schema::hasColumn('products', 'market_checked_at')) {
            $payload['market_checked_at'] = now();
        }
        if (!empty($payload)) {
            $q->update($payload);
        }

        return back()->with('success', 'Preço de mercado atualizado.');
    }
}
