<?php

namespace App\Services\Strategies;

use App\Services\Adapters\MercadoLivreAdapter;
use App\Models\Integration;
use Illuminate\Support\Facades\Http;

class MeliPricingEngine
{
    protected $adapter;

    public function __construct(MercadoLivreAdapter $adapter)
    {
        $this->adapter = $adapter;
    }

    /**
     * Calcula o Preço de Venda Ideal (Engenharia Reversa).
     * * Fórmula: Preço = (Custo + Frete + Fixo) / (1 - (Imposto% + Comissao% + Margem%))
     * * @param float $cost Custo do produto (CMV)
     * @param float $taxRate Alíquota de imposto (ex: 6.0 para 6%)
     * @param float $marginDesired Margem de contribuição desejada (ex: 15.0 para 15%)
     * @param string $categoryId ID da categoria no ML (MLB...)
     * @param string $listingType Tipo do anúncio (gold_special, gold_pro)
     * @param Integration $integration Credenciais da API
     */
    public function calculateIdealPrice(float $cost, float $taxRate, float $marginDesired, string $categoryId, string $listingType, Integration $integration)
    {
        // 1. Definições Iniciais
        $taxPercent = $taxRate / 100;
        $marginPercent = $marginDesired / 100;
        
        // Estimativa inicial do preço para consultar a API (necessário para faixas de comissão)
        // Começamos simulando o dobro do custo para ter uma base
        $basePriceEstimate = $cost * 2; 

        // 2. Consulta API para obter a % de comissão e Custo Fixo da Categoria
        $feeData = $this->getCategoryFees($integration, $basePriceEstimate, $categoryId, $listingType);
        
        $commissionPercent = $feeData['percentage'] / 100; // Ex: 0.14
        $fixedFee = $feeData['fixed_fee']; // Ex: 6.00 se aplicável (< R$ 79)
        
        // 3. Simulação de Frete (Médio ou Grátis)
        // Se o preço estimado for >= 79, adicionamos o custo médio de frete grátis da categoria
        // (Futuramente podemos refinar isso com o peso do produto)
        $shippingCost = 0;
        if ($basePriceEstimate >= 79) {
            $shippingCost = $this->getAverageShippingCost($integration, $categoryId);
        }

        // 4. Aplicação da Fórmula Matemática
        // Denominador: 1 - (Impostos + Comissão + Margem)
        $denominator = 1 - ($taxPercent + $commissionPercent + $marginPercent);

        // Trava de segurança para não dividir por zero ou negativo (margem impossível)
        if ($denominator <= 0) {
            throw new \Exception("A margem desejada é matematicamente impossível com os custos e taxas atuais.");
        }

        $numerator = $cost + $shippingCost + $fixedFee;
        
        $idealPrice = $numerator / $denominator;

        // Se o preço calculado caiu abaixo de 79 e antes tínhamos estimado frete grátis, 
        // ou vice-versa, precisaríamos recalcular recursivamente. 
        // Por enquanto, mantemos a lógica simples.

        return [
            'ideal_price' => round($idealPrice, 2),
            'currency' => 'BRL',
            'breakdown' => [
                'cost_product' => $cost,
                'shipping_cost' => $shippingCost,
                'fixed_fee' => $fixedFee,
                'commission_value' => round($idealPrice * $commissionPercent, 2),
                'commission_percent' => $feeData['percentage'],
                'tax_value' => round($idealPrice * $taxPercent, 2),
                'net_profit' => round($idealPrice * $marginPercent, 2)
            ]
        ];
    }

    /**
     * Consulta a API Oficial de Listing Prices para obter a taxa exata.
     */
    private function getCategoryFees(Integration $integration, $price, $categoryId, $listingType)
    {
        // Usa o helper do Adapter se possível, ou chama direto se precisar de lógica customizada
        $url = "https://api.mercadolibre.com/sites/MLB/listing_prices";
        
        // Verifica conexão no Adapter para garantir token atualizado
        if (!$this->adapter->checkConnection($integration)) {
            throw new \Exception("Integração desconectada.");
        }

        $response = Http::withToken($integration->access_token)->get($url, [
            'price' => $price,
            'listing_type_id' => $listingType,
            'category_id' => $categoryId
        ]);

        if ($response->failed()) {
            // Fallback seguro se a API falhar (Estimativa conservadora)
            return [
                'percentage' => ($listingType === 'gold_pro') ? 18.0 : 13.0,
                'fixed_fee' => ($price < 79) ? 6.00 : 0.0
            ];
        }

        $data = $response->json();
        
        // O ML retorna um array, pegamos o primeiro match
        $pricing = $data[0] ?? [];
        $saleFeeAmount = (float)($pricing['sale_fee_amount'] ?? 0);
        
        // Engenharia reversa para separar % do fixo, caso o payload não detalhe
        $percentage = 0;
        $fixed = 0;

        if ($price > 0) {
            $percentage = ($saleFeeAmount / $price) * 100;
        }

        // Refinamento para itens baratos (onde o fixo distorce a %)
        if ($price < 79) {
            // Assume taxa fixa média de 6.00 e recalcula a % real
            if ($saleFeeAmount > 6) {
                $fixed = 6.00;
                $percentage = (($saleFeeAmount - 6) / $price) * 100;
            } else {
                $fixed = $saleFeeAmount; // Caso estranho onde a taxa é menor que o fixo padrão
                $percentage = 0;
            }
        }

        return [
            'percentage' => round($percentage, 2),
            'fixed_fee' => $fixed
        ];
    }

    /**
     * Obtém custo médio de envio para a categoria (Weight based)
     * @todo Implementar tabela de frete por peso real.
     */
    private function getAverageShippingCost(Integration $integration, $categoryId)
    {
        // Valor base de mercado para envios de até 0.5kg ~ 1kg no Mercado Envios
        // Pode ser parametrizado no banco de dados posteriormente
        return 22.90; 
    }
}