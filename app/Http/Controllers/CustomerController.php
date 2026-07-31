<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Models\Order;
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
        $docExpr = $this->docExpr();
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

    public function show($doc)
    {
        $docExpr = $this->docExpr();
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

        $stats = [
            'total_spent' => $orders->sum('total_amount'),
            'total_orders' => $orders->count(),
            'avg_ticket' => $orders->count() > 0 ? $orders->sum('total_amount') / $orders->count() : 0,
            'first_purchase' => $orders->last()->date_created
        ];

        return Inertia::render('Customers/Show', [
            'customer' => $customer,
            'orders' => $orders,
            'stats' => $stats
        ]);
    }

    /** CPF/CNPJ normalizado (só dígitos), qualquer que seja a coluna onde o canal de origem gravou. */
    private function docExpr(): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(billing_doc_number, customer_doc), '.', ''), '-', ''), '/', ''), ' ', '')";
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
}
