<?php

namespace App\Services\Pricing;

use App\Models\MarketplaceFee;

/**
 * Service especializado em Inteligência de Precificação e BI.
 * Converte as fórmulas complexas da planilha Excel para código escalável.
 */
class PricingIntelligenceService
{
    /**
     * Calcula o Ponto de Equilíbrio (PE) - Break-even.
     * Fórmula: Preço tal que Lucro = 0.
     * 
     * @param float $cost
     * @param MarketplaceFee $feeConfig
     * @return float
     */
    public function calculateBreakEven(float $cost, MarketplaceFee $feeConfig): float
    {
        $tax = $feeConfig->tax_percent / 100;
        $commission = $feeConfig->commission_percent / 100;

        // Fator base sem taxa fixa
        $denominator = 1 - ($tax + $commission);

        if ($denominator <= 0)
            return $cost * 2; // Segurança

        // Cálculo iterativo para taxa fixa condicional
        $estimatedPrice = $cost / $denominator;
        $fixedFee = $feeConfig->getFixedFeeForPrice($estimatedPrice);

        return round(($cost + $fixedFee) / $denominator, 2);
    }

    /**
     * Calcula o Preço Meta para uma margem líquida específica (ex: 15%).
     * 
     * @param float $cost
     * @param MarketplaceFee $feeConfig
     * @param float $targetMargin Margem desejada (ex: 0.15 para 15%)
     * @return float
     */
    public function calculateTargetPrice(float $cost, MarketplaceFee $feeConfig, float $targetMargin = 0.15): float
    {
        $tax = $feeConfig->tax_percent / 100;
        $commission = $feeConfig->commission_percent / 100;

        // Inverte o cálculo: Preço = (Custo + TaxaFixa) / (1 - (Taxa + Comissão + Margem))
        $denominator = 1 - ($tax + $commission + $targetMargin);

        if ($denominator <= 0)
            return $cost / 0.1; // Margem inviável, joga pro alto

        // Estimativa para pegar a taxa fixa correta
        $estimatedPrice = $cost / $denominator;
        $fixedFee = $feeConfig->getFixedFeeForPrice($estimatedPrice);

        return round(($cost + $fixedFee) / $denominator, 2);
    }

    /**
     * Sugere um preço de promoção baseado na saúde do estoque.
     * Se estoque > 1 ano, sugere preço próximo ao PE para girar caixa.
     */
    public function suggestPromotionPrice(float $cost, float $currentPrice, MarketplaceFee $feeConfig, int $daysInStock): float
    {
        $breakeven = $this->calculateBreakEven($cost, $feeConfig);
        $target15 = $this->calculateTargetPrice($cost, $feeConfig, 0.15);

        // Se o item está parado há mais de 2 anos, queima no PE
        if ($daysInStock > 730) {
            return $breakeven;
        }

        // Se parado há mais de 1 ano, aceita lucro de 5%
        if ($daysInStock > 365) {
            return $this->calculateTargetPrice($cost, $feeConfig, 0.05);
        }

        // Caso contrário, tenta manter os 15% ou o preço atual se for maior
        return max($target15, $currentPrice);
    }
}
