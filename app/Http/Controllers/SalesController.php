<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Análise de Vendas — construída sobre os pedidos importados do Magazord.
 * Faturamento por canal, por status, tendência diária e pedidos recentes.
 * Toda leitura é defensiva ao schema variável de `orders`.
 */
class SalesController extends Controller
{
    private const FATURADO = ['approved', 'paid', 'shipped', 'delivered', 'accredited'];

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90, 365], true) ? $days : 30;

        return Inertia::render('Sales/Index', array_merge(
            ['days' => $days],
            $this->build($companyId, $days)
        ));
    }

    private function build(int $companyId, int $days): array
    {
        $empty = [
            'kpis' => ['faturamento' => 0, 'pedidos' => 0, 'ticket' => 0, 'cancelados' => 0, 'cancelado_valor' => 0],
            'por_canal' => [], 'por_status' => [], 'por_dia' => [], 'recentes' => [], 'has_data' => false,
        ];

        try {
            if (!Schema::hasTable('orders')) return $empty;
            $cols = Schema::getColumnListing('orders');
            $has = fn ($c) => in_array($c, $cols, true);

            $totalCol = $has('total_amount') ? 'total_amount' : null;
            if (!$totalCol) return $empty;
            $statusCol = $has('status') ? 'status' : null;
            $channelCol = $has('selling_channel') ? 'selling_channel' : null;
            // Data real do pedido (Data/Hora do Magazord) — NÃO usar created_at,
            // que é o timestamp da importação (jogaria tudo no mesmo mês/dia).
            $dateCol = $has('date_created') ? 'date_created'
                : ($has('order_date') ? 'order_date'
                : ($has('created_at') ? 'created_at' : null));
            $keyCol = $has('external_id') ? 'external_id' : ($has('ml_order_id') ? 'ml_order_id' : 'id');
            $nameCol = $has('customer_name') ? 'customer_name' : ($has('buyer_nickname') ? 'buyer_nickname' : null);
            $hasCompany = $has('company_id');
            $since = Carbon::now()->subDays($days);

            $scope = function () use ($companyId, $hasCompany, $dateCol, $since) {
                $q = DB::table('orders');
                if ($hasCompany) $q->where('company_id', $companyId);
                if ($dateCol) $q->where($dateCol, '>=', $since);
                return $q;
            };
            $faturadoScope = function () use ($scope, $statusCol) {
                $q = $scope();
                if ($statusCol) $q->whereIn($statusCol, self::FATURADO);
                return $q;
            };

            $faturamento = (float) $faturadoScope()->sum($totalCol);
            $pedidos = (int) $faturadoScope()->count();

            $cancelados = 0; $canceladoValor = 0.0;
            if ($statusCol) {
                $cancelados = (int) $scope()->where($statusCol, 'cancelled')->count();
                $canceladoValor = (float) $scope()->where($statusCol, 'cancelled')->sum($totalCol);
            }

            $porCanal = [];
            if ($channelCol) {
                $porCanal = $faturadoScope()
                    ->select(DB::raw("$channelCol as canal"), DB::raw("SUM($totalCol) as total"), DB::raw('COUNT(*) as pedidos'))
                    ->groupBy($channelCol)->orderByDesc('total')->limit(12)->get()
                    ->map(fn ($r) => ['canal' => $r->canal ?: 'Sem canal', 'total' => (float) $r->total, 'pedidos' => (int) $r->pedidos])->all();
            }

            $porStatus = [];
            if ($statusCol) {
                $porStatus = $scope()
                    ->select(DB::raw("$statusCol as status"), DB::raw("SUM($totalCol) as total"), DB::raw('COUNT(*) as pedidos'))
                    ->groupBy($statusCol)->orderByDesc('pedidos')->get()
                    ->map(fn ($r) => ['status' => $r->status ?: 'indefinido', 'total' => (float) $r->total, 'pedidos' => (int) $r->pedidos])->all();
            }

            $porDia = [];
            if ($dateCol) {
                $porDia = $faturadoScope()
                    ->select(DB::raw("DATE($dateCol) as dia"), DB::raw("SUM($totalCol) as total"))
                    ->groupBy(DB::raw("DATE($dateCol)"))->orderBy('dia')->get()
                    ->map(fn ($r) => ['dia' => $r->dia, 'total' => (float) $r->total])->all();
            }

            $selRec = ["$keyCol as pedido", "$totalCol as total"];
            if ($statusCol) $selRec[] = "$statusCol as status";
            if ($channelCol) $selRec[] = "$channelCol as canal";
            if ($nameCol) $selRec[] = "$nameCol as cliente";
            if ($dateCol) $selRec[] = "$dateCol as data";
            $recentesQ = $scope()->select(DB::raw(implode(', ', $selRec)));
            if ($dateCol) $recentesQ->orderByDesc($dateCol);
            $recentes = $recentesQ->limit(40)->get()->map(fn ($r) => [
                'pedido' => $r->pedido,
                'cliente' => $r->cliente ?? '—',
                'canal' => $r->canal ?? '—',
                'status' => $r->status ?? '—',
                'total' => (float) $r->total,
                'data' => isset($r->data) ? (string) $r->data : null,
            ])->all();

            return [
                'kpis' => [
                    'faturamento' => round($faturamento, 2),
                    'pedidos' => $pedidos,
                    'ticket' => $pedidos > 0 ? round($faturamento / $pedidos, 2) : 0,
                    'cancelados' => $cancelados,
                    'cancelado_valor' => round($canceladoValor, 2),
                ],
                'por_canal' => $porCanal,
                'por_status' => $porStatus,
                'por_dia' => $porDia,
                'recentes' => $recentes,
                'has_data' => $pedidos > 0 || !empty($recentes),
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }
}
