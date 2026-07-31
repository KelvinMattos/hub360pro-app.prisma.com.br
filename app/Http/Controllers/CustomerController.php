<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Order;
use App\Services\Customers\CustomerIdentityService;
use Inertia\Inertia;

/**
 * Lista de clientes agrupada por CPF/CNPJ — funciona independente do canal
 * de origem do pedido, mesmo pra pedidos antigos sem customer_id vinculado
 * (ver CustomerIdentityService, que popula customer_id nas importações
 * novas, mas não retroage sobre o histórico já importado).
 *
 * O CPF pode ter vindo em `billing_doc_number` (Mercado Livre/Shopee) ou
 * `customer_doc` (Magazord/Netshoes) — nunca os dois ao mesmo tempo — e com
 * ou sem máscara (".", "-", "/"). Normaliza os dois problemas antes de
 * agrupar, senão o mesmo cliente aparece picotado em várias linhas.
 */
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $docExpr = CustomerIdentityService::sqlDocExpr();
        $nameExpr = $this->nameExpr();

        $query = Order::where('company_id', Auth::user()->company_id)
            ->whereRaw("$docExpr IS NOT NULL AND $docExpr != ''")
            ->select(
                DB::raw("$docExpr as billing_doc_number"),
                DB::raw("MAX($nameExpr) as customer_name"),
                DB::raw('MAX(date_created) as last_purchase'),
                DB::raw('COUNT(id) as total_orders'),
                DB::raw('SUM(total_amount) as total_spent')
            )
            ->groupBy(DB::raw($docExpr));

        if ($request->filled('search')) {
            $term = $request->search;
            $query->where(function ($q) use ($term, $docExpr, $nameExpr) {
                $q->whereRaw("$nameExpr like ?", ["%{$term}%"])
                    ->orWhereRaw("$docExpr like ?", ["%{$term}%"]);
            });
        }

        $customers = $query->orderBy('last_purchase', 'desc')->paginate(20);

        return Inertia::render('Customers/Index', [
            'customers' => $customers,
            'filters' => $request->only(['search'])
        ]);
    }

    /**
     * Perfil de consumo completo do cliente: LTV, frequência, ticket médio,
     * recência, produtos comprados (via order_items) e canais usados —
     * tudo sobre o histórico de compras dele, não só a lista de pedidos.
     */
    public function show($doc)
    {
        $docExpr = CustomerIdentityService::sqlDocExpr();
        $docClean = preg_replace('/[^0-9]/', '', (string) $doc);

        $orders = Order::where('company_id', Auth::user()->company_id)
            ->whereRaw("$docExpr = ?", [$docClean])
            ->orderBy('date_created', 'desc')
            ->get();

        if ($orders->isEmpty()) {
            return redirect()->route('customers.index')->with('error', 'Cliente não encontrado.');
        }

        $customer = $orders->first();
        // Garante que a tela mostre o CPF normalizado mesmo se este pedido em
        // particular só tiver preenchido customer_doc (não billing_doc_number).
        $customer->billing_doc_number = $docClean;
        // Alguns pedidos do mesmo cliente podem ter vindo sem nome — usa o
        // primeiro não vazio entre todos os pedidos dele.
        $customer->customer_name = $this->bestName($orders);

        $mostRecent = $orders->first();
        $oldest = $orders->last();
        $totalOrders = $orders->count();
        $totalSpent = (float) $orders->sum('total_amount');

        $stats = [
            'total_spent' => $totalSpent,
            'total_orders' => $totalOrders,
            'avg_ticket' => $totalOrders > 0 ? $totalSpent / $totalOrders : 0,
            'first_purchase' => $oldest->date_created,
            'last_purchase' => $mostRecent->date_created,
            'days_since_last_purchase' => $mostRecent->date_created ? (int) abs(now()->diffInDays($mostRecent->date_created)) : null,
        ];

        $porCanal = $orders->groupBy(fn ($o) => $o->selling_channel ?: 'Sem canal')
            ->map(fn ($group, $canal) => [
                'canal' => $canal, 'pedidos' => $group->count(), 'total' => (float) $group->sum('total_amount'),
            ])
            ->sortByDesc('total')
            ->values()
            ->all();

        $produtos = [];
        if (Schema::hasTable('order_items')) {
            $produtos = DB::table('order_items')
                ->whereIn('order_id', $orders->pluck('id'))
                ->select(
                    'sku',
                    DB::raw('MAX(title) as titulo'),
                    DB::raw('SUM(quantity) as unidades'),
                    DB::raw('SUM(unit_price * quantity) as total')
                )
                ->groupBy('sku')
                ->orderByDesc('total')
                ->get()
                ->map(fn ($r) => [
                    'sku' => $r->sku,
                    'titulo' => $r->titulo ?: ($r->sku ?: '—'),
                    'unidades' => (int) $r->unidades,
                    'total' => (float) $r->total,
                ])
                ->all();
        }

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'orders' => $orders,
            'stats' => $stats,
            'por_canal' => $porCanal,
            'produtos' => $produtos,
        ]);
    }

    /**
     * Nome do cliente: `customer_name` nem sempre existe (depende de qual
     * migration rodou primeiro — ver CLAUDE.md §4), `buyer_nickname` é o
     * nome legado só de Mercado Livre. Resolve de forma defensiva pra nunca
     * referenciar coluna inexistente.
     */
    private function nameExpr(): string
    {
        $cols = Schema::getColumnListing('orders');
        $hasName = in_array('customer_name', $cols, true);
        $hasNickname = in_array('buyer_nickname', $cols, true);

        if ($hasName && $hasNickname) {
            return 'COALESCE(customer_name, buyer_nickname)';
        }
        if ($hasName) {
            return 'customer_name';
        }
        if ($hasNickname) {
            return 'buyer_nickname';
        }
        return "'—'";
    }

    /** Primeiro nome não vazio entre os pedidos do cliente (alguns podem ter vindo sem nome). */
    private function bestName($orders): ?string
    {
        $nameCols = array_values(array_intersect(['customer_name', 'buyer_nickname'], Schema::getColumnListing('orders')));

        foreach ($orders as $order) {
            foreach ($nameCols as $col) {
                if (!empty($order->{$col})) {
                    return $order->{$col};
                }
            }
        }

        return null;
    }
}
