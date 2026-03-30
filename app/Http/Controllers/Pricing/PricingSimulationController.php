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
        try {
            $savedScenarios = PricingSimulation::activeDrafts()->latest()->take(10)->get();
        }
        catch (\Exception $e) {
            // Logar o erro se necessário, mas não quebrar a aplicação
            \Log::warning("Erro ao carregar cenários de simulação: " . $e->getMessage());
            $savedScenarios = collect([]);
        }

        return Inertia::render('Pricing/Simulator', [
            'savedScenarios' => $savedScenarios,
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
