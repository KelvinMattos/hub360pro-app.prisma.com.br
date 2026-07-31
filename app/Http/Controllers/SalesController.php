<?php

namespace App\Http\Controllers;

use App\Services\Customers\CustomerIdentityService;
use App\Services\Sales\SalesAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Central de Vendas — faturamento por canal, status, mês e região (via
 * SalesAnalyticsService), tendência diária, marcas/produtos mais vendidos e
 * pedidos recentes. Toda leitura é defensiva ao schema variável de `orders`.
 */
class SalesController extends Controller
{
    public function index(Request $request, SalesAnalyticsService $analytics)
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
            $this->build($companyId, $days, $analytics)
        ));
    }

    private function build(int $companyId, int $days, SalesAnalyticsService $analytics): array
    {
        $empty = [
            'kpis' => ['faturamento' => 0, 'pedidos' => 0, 'ticket' => 0, 'cancelados' => 0, 'cancelado_valor' => 0, 'variacao_pct' => null],
            'por_canal' => [], 'por_status' => [], 'por_dia' => [], 'mensal' => [],
            'por_regiao_estado' => [], 'por_regiao_macro' => [], 'por_marca' => [], 'top_produtos' => [],
            'recentes' => [], 'has_data' => false,
        ];

        try {
            if (!$analytics->schemaReady()) {
                return $empty;
            }

            $porRegiaoEstado = $analytics->porRegiaoEstado($companyId, $days);

            $result = [
                'kpis' => $analytics->kpis($companyId, $days),
                'por_canal' => $analytics->porCanal($companyId, $days),
                'por_status' => $analytics->porStatus($companyId, $days),
                'por_dia' => $analytics->porDia($companyId, $days),
                'mensal' => $analytics->tendenciaMensal($companyId, 12),
                'por_regiao_estado' => $porRegiaoEstado,
                'por_regiao_macro' => $analytics->porRegiaoMacro($porRegiaoEstado),
                'por_marca' => $analytics->porMarca($companyId, $days),
                'top_produtos' => $analytics->topProdutos($companyId, $days),
                'recentes' => $this->recentes($companyId, $days),
            ];
            $result['has_data'] = $result['kpis']['pedidos'] > 0 || !empty($result['recentes']);

            return $result;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /** Lista de pedidos recentes — mantém a leitura defensiva original (colunas variam por origem). */
    private function recentes(int $companyId, int $days): array
    {
        $cols = Schema::getColumnListing('orders');
        $has = fn ($c) => in_array($c, $cols, true);

        $totalCol = $has('total_amount') ? 'total_amount' : null;
        if (!$totalCol) {
            return [];
        }

        $statusCol = $has('status') ? 'status' : null;
        $channelCol = $has('selling_channel') ? 'selling_channel' : null;
        $dateCol = $has('date_created') ? 'date_created' : ($has('order_date') ? 'order_date' : ($has('created_at') ? 'created_at' : null));
        $hasMlOrderId = $has('ml_order_id');
        $hasCustomerName = $has('customer_name');
        $hasBuyerNickname = $has('buyer_nickname');
        $hasBillingDoc = $has('billing_doc_number');
        $hasCustomerDoc = $has('customer_doc');
        $canJoinCustomers = $has('customer_id') && Schema::hasTable('customers') && Schema::hasColumn('customers', 'name');
        $hasCompany = $has('company_id');
        $since = Carbon::now()->subDays($days);

        $q = DB::table('orders as o');
        if ($canJoinCustomers) {
            $q->leftJoin('customers as c', 'c.id', '=', 'o.customer_id');
        }
        if ($hasCompany) {
            $q->where('o.company_id', $companyId);
        }
        if ($dateCol) {
            $q->where("o.$dateCol", '>=', $since);
        }

        // Nº do pedido: prioriza ml_order_id (pedidos vindos da API), cai pro
        // id interno quando é nulo — comum em pedidos importados via CSV
        // Magazord/Netshoes, que nunca preenchem esse campo (CLAUDE.md §5.1).
        // Antes disso, se ml_order_id existisse como coluna mas estivesse
        // vazio pra esses pedidos, a coluna "Pedido" toda ficava em branco.
        $keyExpr = $hasMlOrderId ? 'COALESCE(o.ml_order_id, o.id)' : 'o.id';
        $selRec = ["$keyExpr as pedido", "o.$totalCol as total"];
        if ($statusCol) {
            $selRec[] = "o.$statusCol as status";
        }
        if ($channelCol) {
            $selRec[] = "o.$channelCol as canal";
        }
        // Nome do cliente: prioriza customers.name (alimentado por qualquer
        // origem, via customer_id), cai pro customer_name/buyer_nickname do
        // próprio pedido — nessa ordem, senão pedidos Magazord/Netshoes
        // (que gravam em customer_name, não buyer_nickname) ficavam em branco.
        $orderNameExpr = match (true) {
            $hasCustomerName && $hasBuyerNickname => 'o.customer_name, o.buyer_nickname',
            $hasCustomerName => 'o.customer_name',
            $hasBuyerNickname => 'o.buyer_nickname',
            default => null,
        };
        if ($canJoinCustomers && $orderNameExpr) {
            $selRec[] = "COALESCE(c.name, $orderNameExpr) as cliente";
        } elseif ($canJoinCustomers) {
            $selRec[] = 'c.name as cliente';
        } elseif ($orderNameExpr) {
            $selRec[] = "COALESCE($orderNameExpr) as cliente";
        }
        // CPF normalizado, pra "Cliente" virar link pro perfil de consumo.
        if ($hasBillingDoc || $hasCustomerDoc) {
            $docExpr = CustomerIdentityService::sqlDocExpr(
                $hasBillingDoc ? 'o.billing_doc_number' : 'NULL',
                $hasCustomerDoc ? 'o.customer_doc' : 'NULL'
            );
            $selRec[] = "$docExpr as doc";
        }
        if ($dateCol) {
            $selRec[] = "o.$dateCol as data";
        }

        $q->select(DB::raw(implode(', ', $selRec)));
        if ($dateCol) {
            $q->orderByDesc("o.$dateCol");
        }

        return $q->limit(40)->get()->map(fn ($r) => [
            'pedido' => $r->pedido,
            'cliente' => $r->cliente ?? '—',
            'doc' => (isset($r->doc) && $r->doc !== '') ? $r->doc : null,
            'canal' => $r->canal ?? '—',
            'status' => $r->status ?? '—',
            'total' => (float) $r->total,
            'data' => isset($r->data) ? (string) $r->data : null,
        ])->all();
    }
}
