<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PricingSimulatorController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $products = Product::where('company_id', $user->company_id)
            ->where('status', 'active')
            ->orderBy('title')
            ->get(['id', 'title', 'sale_price', 'cost_price', 'sku']);

        return Inertia::render('Pricing/Simulator', [
            'products' => $products
        ]);
    }

    public function simulate(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'price_change_percent' => 'required|numeric',
            'ads_change_percent' => 'required|numeric',
        ]);

        $product = Product::findOrFail($validated['product_id']);
        $companyId = Auth::user()->company_id;

        // Dados Históricos Reais (Últimos 30 dias)
        $salesVolume = Order::where('company_id', $companyId)
            ->whereHas('items', function ($query) use ($product) {
                $query->where('product_id', $product->id);
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // 1. Cenário Atual
        $currentPrice = (float)$product->sale_price;
        $cost = (float)$product->cost_price ?? ($currentPrice * 0.4); // Fallback caso não tenha custo
        $taxRate = 0.12; // Média estimada
        $commRate = 0.16; // Média Meli Premium
        $shipping = 19.90; // Média simplificada

        $currentMargin = $currentPrice - $cost - ($currentPrice * $taxRate) - ($currentPrice * $commRate) - $shipping;
        $currentTotalProfit = $currentMargin * $salesVolume;

        // 2. Novo Cenário (Simulado)
        $newPrice = $currentPrice * (1 + ($validated['price_change_percent'] / 100));
        
        // Elasticidade Preditiva (Simplificada: 1% de aumento de preço = 2.5% queda de volume)
        $elasticity = -2.5; 
        $volumeImpact = 1 + (($validated['price_change_percent'] / 100) * $elasticity);
        $newVolume = max(0, $salesVolume * $volumeImpact);

        $newMargin = $newPrice - $cost - ($newPrice * $taxRate) - ($newPrice * $commRate) - $shipping;
        $newTotalProfit = $newMargin * $newVolume;

        return response()->json([
            'current' => [
                'price' => $currentPrice,
                'margin' => $currentMargin,
                'volume' => $salesVolume,
                'total_profit' => $currentTotalProfit
            ],
            'simulated' => [
                'price' => $newPrice,
                'margin' => $newMargin,
                'volume' => $newVolume,
                'total_profit' => $newTotalProfit,
                'impact' => [
                    'profit_percent' => $currentTotalProfit > 0 ? (($newTotalProfit - $currentTotalProfit) / $currentTotalProfit) * 100 : 0,
                    'volume_percent' => $salesVolume > 0 ? (($newVolume - $salesVolume) / $salesVolume) * 100 : 0
                ]
            ],
            'recommendation' => $newTotalProfit > $currentTotalProfit ? 'Positiva' : 'Negativa'
        ]);
    }
}
