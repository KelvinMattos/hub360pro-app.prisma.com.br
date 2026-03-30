<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Auth;

class ExpeditionController extends Controller
{
    public function index()
    {
        $companyId = Auth::user()->company_id;

        // Pedidos aguardando expedição (status: paid or ready_to_ship)
        $orders = Order::where('company_id', $companyId)
            ->whereIn('status', ['paid', 'ready_to_ship'])
            ->with('items.product')
            ->latest()
            ->get();

        return Inertia::render('Orders/Expedition', [
            'orders' => $orders
        ]);
    }

    public function pack(Request $request, $id)
    {
        $order = Order::findOrFail($id);
        
        // Simulação de conferência (Picking & Packing)
        $order->update(['status' => 'shipped']);
        
        return back()->with('success', "Pedido #{$order->external_id} conferido e pronto para envio!");
    }
}
