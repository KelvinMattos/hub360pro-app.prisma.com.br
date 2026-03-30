<?php

namespace App\Actions\Pricing;

use App\Models\Product;
use App\Models\PricingSimulation;
use App\Services\Pricing\PricingService;

/**
 * Action responsável por executar uma simulação para um produto específico dentro de um cenário.
 */
class PerformProductSimulationAction
{
    private $pricingService;

    public function __construct(PricingService $pricingService)
    {
        $this->pricingService = $this->pricingService = $pricingService;
    }

    /**
     * Gera ou atualiza um registro de simulação.
     * 
     * @param int $scenarioId ID do cenário agrupador
     * @param int $productId ID do anúncio a ser simulado
     * @param array $simulatedData Dados alterados pelo usuário
     * @return PricingSimulation
     */
    public function execute(int $scenarioId, int $productId, array $simulatedData): PricingSimulation
    {
        // Aqui buscaríamos o config real para preencher o que não for enviado no $simulatedData
        // Por brevidade, assumimos que o payload já contém o delta.

        $suggestedPrice = $this->pricingService->calculateSuggestedPrice(
            $simulatedData['cost_price'],
            $simulatedData['shipping_cost'] ?? 0,
            $simulatedData['fixed_fee'] ?? 0,
            $simulatedData['tax_percent'] ?? 0,
            $simulatedData['commission_percent'] ?? 0,
            $simulatedData['active_margin'] ?? 10
        );

        return PricingSimulation::updateOrCreate(
        ['scenario_id' => $scenarioId, 'product_id' => $productId],
            array_merge($simulatedData, ['suggested_price' => $suggestedPrice])
        );
    }
}
