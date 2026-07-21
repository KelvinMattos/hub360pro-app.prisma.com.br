<?php

namespace App\Http\Controllers;

use App\Services\ManagementDecisionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Dashboard Gerencial (executivo).
 *
 * Visão de topo resiliente: vendas reais (pedidos importados), saúde de estoque
 * e capital, e o resumo de decisão (margem, prejuízo, promoções perigosas) —
 * com atalhos para o Centro de Decisão. Toda leitura é defensiva (guards de
 * coluna/tabela e try/catch) para nunca derrubar a home.
 */
class DashboardController extends Controller
{
    public function index(ManagementDecisionService $decision)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        try {
            $analysis = $decision->analyze($companyId);
            $kpis = $analysis['kpis'];
            $channel = $analysis['channel'];
            $alertas = [
                'prejuizo' => array_slice($analysis['alertas']['prejuizo'], 0, 6),
                'liquidar' => array_slice($analysis['alertas']['liquidar'], 0, 6),
                'oportunidade' => array_slice($analysis['alertas']['oportunidade'], 0, 6),
            ];
        } catch (\Throwable $e) {
            $kpis = []; $channel = []; $alertas = [];
        }

        return Inertia::render('ManagementDashboard', [
            'sales' => $this->salesSummary($companyId),
            'kpis' => $kpis,
            'channel' => $channel,
            'alertas' => $alertas,
        ]);
    }

    /** Resumo de vendas a partir dos pedidos importados (resiliente ao schema). */
    private function salesSummary(int $companyId): array
    {
        $empty = ['rev30' => 0, 'rev_today' => 0, 'orders30' => 0, 'ticket' => 0, 'total_pedidos' => 0, 'por_canal' => []];

        try {
            if (!Schema::hasTable('orders')) return $empty;
            $cols = Schema::getColumnListing('orders');
            $has = fn ($c) => in_array($c, $cols, true);

            $totalCol = $has('total_amount') ? 'total_amount' : null;
            if (!$totalCol) return $empty;
            $statusCol = $has('status') ? 'status' : null;
            $channelCol = $has('selling_channel') ? 'selling_channel' : null;
            $dateCol = $has('created_at') ? 'created_at' : ($has('date_created') ? 'date_created' : null);
            $hasCompany = $has('company_id');

            $faturado = ['approved', 'paid', 'shipped', 'delivered', 'accredited'];
            $now = Carbon::now();

            $scope = function () use ($companyId, $hasCompany, $statusCol, $faturado) {
                $q = DB::table('orders');
                if ($hasCompany) $q->where('company_id', $companyId);
                if ($statusCol) $q->whereIn($statusCol, $faturado);
                return $q;
            };

            $q30 = $scope();
            if ($dateCol) $q30->where($dateCol, '>=', $now->copy()->subDays(30));
            $rev30 = (float) $q30->sum($totalCol);

            $qc = $scope();
            if ($dateCol) $qc->where($dateCol, '>=', $now->copy()->subDays(30));
            $orders30 = (int) $qc->count();

            $qt = $scope();
            if ($dateCol) $qt->where($dateCol, '>=', $now->copy()->startOfDay());
            $revToday = (float) $qt->sum($totalCol);

            $porCanal = [];
            if ($channelCol) {
                $rows = $scope()
                    ->select(DB::raw("$channelCol as canal"), DB::raw("SUM($totalCol) as total"), DB::raw('COUNT(*) as pedidos'))
                    ->groupBy($channelCol)->orderByDesc('total')->limit(8)->get();
                $porCanal = $rows->map(fn ($r) => [
                    'canal' => $r->canal ?: 'Sem canal',
                    'total' => (float) $r->total,
                    'pedidos' => (int) $r->pedidos,
                ])->all();
            }

            $totalBase = DB::table('orders');
            if ($hasCompany) $totalBase->where('company_id', $companyId);

            return [
                'rev30' => round($rev30, 2),
                'rev_today' => round($revToday, 2),
                'orders30' => $orders30,
                'ticket' => $orders30 > 0 ? round($rev30 / $orders30, 2) : 0,
                'total_pedidos' => (int) $totalBase->count(),
                'por_canal' => $porCanal,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    public function sync()
    {
        $user = Auth::user();
        $integration = \App\Models\Integration::where('company_id', $user->company_id)
            ->whereIn('platform', ['mercadolibre', 'mercadolivre'])
            ->first();

        if ($integration) {
            app(\App\Services\MarketplaceListingService::class)->syncListings($integration);
            return redirect()->back()->with('success', 'Sincronização iniciada com sucesso.');
        }

        return redirect()->back()->with('error', 'Nenhuma integração configurada.');
    }
}
