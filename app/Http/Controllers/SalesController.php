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
 * SalesAnalyticsService), tendência diária, marcas/produtos/clientes mais
 * vendidos e pedidos recentes. Toda leitura é defensiva ao schema variável
 * de `orders`.
 *
 * Período: `days` (padrão, janela relativa a agora) OU um range explícito —
 * `from`+`to` (datas personalizadas) OU `month` (Y-m, mês específico
 * completo, passado ou presente). Um range explícito sempre vence `days`.
 */
class SalesController extends Controller
{
    private const DAY_PRESETS = [7, 30, 90, 365];

    public function index(Request $request, SalesAnalyticsService $analytics)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $days = (int) $request->query('days', 30);
        $days = in_array($days, self::DAY_PRESETS, true) ? $days : 30;

        [$range, $rangeMeta] = $this->resolveRange($request);

        return Inertia::render('Sales/Index', array_merge(
            ['days' => $days, 'filters' => $rangeMeta],
            $this->build($companyId, $days, $range, $analytics)
        ));
    }

    /**
     * Lê `month` (Y-m) ou `from`/`to` (Y-m-d) da querystring e devolve
     * [range, meta]. `range` é `[Carbon since, Carbon until]` ou null (usa
     * `days`); `meta` é o que a tela ecoa de volta nos filtros — nunca
     * confia em data inválida digitada na URL, cai pro modo `days` em vez
     * de quebrar a página.
     */
    private function resolveRange(Request $request): array
    {
        $month = $request->query('month');
        if ($month && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            try {
                $since = Carbon::createFromFormat('Y-m-d', "$month-01")->startOfMonth();
                $until = $since->copy()->endOfMonth();

                return [[$since, $until], ['mode' => 'month', 'month' => $month]];
            } catch (\Throwable $e) {
                // cai pro fallback abaixo
            }
        }

        $from = $request->query('from');
        $to = $request->query('to');
        if ($from && $to) {
            try {
                $since = Carbon::createFromFormat('Y-m-d', $from)->startOfDay();
                $until = Carbon::createFromFormat('Y-m-d', $to)->endOfDay();
                if ($since->lte($until)) {
                    return [[$since, $until], ['mode' => 'range', 'from' => $from, 'to' => $to]];
                }
            } catch (\Throwable $e) {
                // data inválida — cai pro fallback abaixo
            }
        }

        return [null, ['mode' => 'days']];
    }

    private function build(int $companyId, int $days, ?array $range, SalesAnalyticsService $analytics): array
    {
        $empty = [
            'kpis' => ['faturamento' => 0, 'pedidos' => 0, 'ticket' => 0, 'cancelados' => 0, 'cancelado_valor' => 0, 'taxa_cancelamento_pct' => 0, 'variacao_pct' => null],
            'por_canal' => [], 'por_status' => [], 'por_dia' => [], 'mensal' => [],
            'por_regiao_estado' => [], 'por_regiao_macro' => [], 'por_marca' => [], 'top_produtos' => [],
            'top_clientes' => [], 'clientes_resumo' => ['total_clientes' => 0, 'multicanal' => 0],
            'recentes' => [], 'has_data' => false,
        ];

        try {
            if (!$analytics->schemaReady()) {
                return $empty;
            }

            $porRegiaoEstado = $analytics->porRegiaoEstado($companyId, $days, 15, $range);

            $result = [
                'kpis' => $analytics->kpis($companyId, $days, $range),
                'por_canal' => $analytics->porCanal($companyId, $days, 12, $range),
                'por_status' => $analytics->porStatus($companyId, $days, $range),
                'por_dia' => $analytics->porDia($companyId, $days, $range),
                'mensal' => $analytics->tendenciaMensal($companyId, 12),
                'por_regiao_estado' => $porRegiaoEstado,
                'por_regiao_macro' => $analytics->porRegiaoMacro($porRegiaoEstado),
                'por_marca' => $analytics->porMarca($companyId, $days, 10, $range),
                'top_produtos' => $analytics->topProdutos($companyId, $days, 10, $range),
                'top_clientes' => $analytics->topClientes($companyId, $days, 10, $range),
                'clientes_resumo' => $analytics->clientesResumo($companyId, $days, $range),
                'recentes' => $this->recentes($companyId, $days, $range),
            ];
            $result['has_data'] = $result['kpis']['pedidos'] > 0 || !empty($result['recentes']);

            return $result;
        } catch (\Throwable $e) {
            return $empty;
        }
    }

    /** Lista de pedidos recentes — mantém a leitura defensiva original (colunas variam por origem). */
    private function recentes(int $companyId, int $days, ?array $range = null): array
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
        [$since, $until] = $range ?? [Carbon::now()->subDays($days), Carbon::now()];

        $q = DB::table('orders as o');
        if ($canJoinCustomers) {
            $q->leftJoin('customers as c', 'c.id', '=', 'o.customer_id');
        }
        if ($hasCompany) {
            $q->where('o.company_id', $companyId);
        }
        if ($dateCol) {
            $q->where("o.$dateCol", '>=', $since)->where("o.$dateCol", '<=', $until);
        }

        // Nº do pedido: prioriza ml_order_id (pedidos vindos da API), cai pro
        // id interno quando é nulo — comum em pedidos importados via CSV
        // Magazord/Netshoes, que nunca preenchem esse campo (CLAUDE.md §5.1).
        // Antes disso, se ml_order_id existisse como coluna mas estivesse
        // vazio pra esses pedidos, a coluna "Pedido" toda ficava em branco.
        $keyExpr = $hasMlOrderId ? 'COALESCE(o.ml_order_id, o.id)' : 'o.id';
        $selRec = ['o.id as id', "$keyExpr as pedido", "o.$totalCol as total"];
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
            'id' => $r->id,
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
