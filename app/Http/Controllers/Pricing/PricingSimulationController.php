<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use App\Models\PricingSimulation;
use App\Http\Requests\Pricing\StorePricingSimulationRequest;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Illuminate\Http\Request;

/**
 * Controller de Alta Performance para o Simulador 360 PRO.
 * Atua como ponte entre a reatividade do Vue 3 e a persistência do Laravel.
 */
class PricingSimulationController extends Controller
{
    /**
     * Exibe o simulador.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Busca produtos ativos para popular o dropdown do simulador
        $products = \App\Models\Product::where('company_id', $user->company_id)
            ->where('status', 'active')
            ->orderBy('title')
            ->get(['id', 'title', 'sale_price', 'cost_price', 'sku']);

        try {
            $savedScenarios = PricingSimulation::activeDrafts()->latest()->take(10)->get();
        }
        catch (\Exception $e) {
            \Log::warning("Erro ao carregar cenários de simulação: " . $e->getMessage());
            $savedScenarios = collect([]);
        }

        return Inertia::render('Pricing/Simulator', [
            'products' => $products,
            'savedScenarios' => $savedScenarios,
        ]);
    }

    /**
     * Lógica de Simulação Preditiva (Elasticidade de Preço & Lucro).
     */
    public function simulate(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'price_change_percent' => 'required|numeric',
            'ads_change_percent' => 'required|numeric',
        ]);

        $product = \App\Models\Product::findOrFail($validated['product_id']);
        $companyId = Auth::user()->company_id;

        // Dados Históricos Reais (Últimos 30 dias) - Volume de Vendas
        $salesVolume = \App\Models\Order::where('company_id', $companyId)
            ->whereHas('items', function ($query) use ($product) {
                $query->where('product_id', $product->id);
            })
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        // 1. Cenário Atual
        $currentPrice = (float)$product->sale_price;
        $cost = (float)($product->cost_price ?? ($currentPrice * 0.4));
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
        
        // Impacto adicional de Ads no Volume (1% de verba extra = 0.5% ganho de volume)
        $adsImpact = ($validated['ads_change_percent'] / 100) * 0.5;
        $volumeImpact += $adsImpact;

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

    /**
     * Salva um cenário de simulação validado.
     * Os cálculos já vêm prontos do frontend, o backend atua como validador e arquivo.
     */
    public function store(StorePricingSimulationRequest $request)
    {
        // Dados validados pelo FormRequest (incluindo o hook after())
        $data = $request->validated();

        // Persistência com isolamento garantido pelo Booted do Model
        PricingSimulation::create($data);

        return back()->with('success', 'Cenário estratégico salvo com sucesso! Acesse seu histórico para comparar.');
    }
}
