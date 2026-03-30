<?php

namespace App\Services\Pricing;

/**
 * Service dedicado a centralizar a lógica financeira de precificação estratégica.
 * Implementa a fórmula: Preço = (Custo + Frete + Taxa Fixa) / (1 - Sum(Taxas%))
 */
class PricingService
{
    /**
     * Calcula o preço sugerido com base nos custos e margem desejada.
     * 
     * @param float $costPrice Preço de custo do item
     * @param float $shippingCost Custo de frete imputado ao lojista
     * @param float $fixedFee Taxa fixa de marketplace ou administrativa
     * @param float $taxPercent Percentual de tributação (Impostos)
     * @param float $commissionPercent Taxa de comissão do canal/marketplace
     * @param float $desiredMarginPercent Margem de lucro líquida desejada
     * @return float Preço de venda final sugerido
     * @throws \Exception Quando a soma das taxas ultrapassa 100% (inválido)
     */
    public function calculateSuggestedPrice(
        float $costPrice,
        float $shippingCost,
        float $fixedFee,
        float $taxPercent,
        float $commissionPercent,
        float $desiredMarginPercent
        ): float
    {
        // Soma dos custos absolutos
        $totalFixedCosts = $costPrice + $shippingCost + $fixedFee;

        // Soma dos custos percentuais convertidos para decimal
        $totalPercentageRates = ($taxPercent + $commissionPercent + $desiredMarginPercent) / 100;

        // Fator divisor (Denominator)
        $denominator = 1 - $totalPercentageRates;

        // Validação de margens impossíveis
        if ($denominator <= 0) {
            throw new \Exception("A soma das taxas percentuais excede a capacidade do markup. Margem impossível.");
        }

        // Cálculo final arredondado
        return round($totalFixedCosts / $denominator, 2);
    }

    /**
     * Calcula a Margem de Contribuição real dado um preço de venda específico.
     * Útil para o Simulador mostrar "E se eu vender por X, qual minha margem?".
     */
    public function calculateMargin(
        float $salePrice,
        float $costPrice,
        float $shippingCost,
        float $fixedFee,
        float $taxPercent,
        float $commissionPercent
        ): float
    {
        if ($salePrice <= 0)
            return 0;

        $taxesAmount = ($taxPercent / 100) * $salePrice;
        $commissionAmount = ($commissionPercent / 100) * $salePrice;

        $profit = $salePrice - $costPrice - $shippingCost - $fixedFee - $taxesAmount - $commissionAmount;

        return round(($profit / $salePrice) * 100, 2);
    }
}
