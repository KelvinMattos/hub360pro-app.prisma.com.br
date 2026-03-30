<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Company;
use App\Models\Integration;
use App\Models\OperationalExpense;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class FinancialService
{
    /**
     * Calcula o Preço Sugerido (Markup Reverso)
     */
    public function calculateSuggestedPrice($costPrice, Company $company, Integration $integration = null)
    {
        if ($costPrice <= 0)
            return 0.00;

        $imposto = ($company->default_tax ?? 10) / 100;
        $margemDesejada = ($company->default_margin ?? 20) / 100;

        $comissao = $integration ? ($integration->commission_percent / 100) : 0.18;
        $taxaFixa = $integration ? $integration->fixed_fee : 0;

        $divisor = 1 - ($imposto + $comissao + $margemDesejada);

        if ($divisor <= 0)
            return $costPrice * 2.5;

        $precoSugerido = ($costPrice + $taxaFixa) / $divisor;
        return round($precoSugerido, 2);
    }

    /**
     * Calcula taxas Reais (ML e Shopee)
     */
    public function calculateMarketplaceFees($price, $listingType = 'standard', $platform = 'mercadolibre')
    {
        // --- LÓGICA SHOPEE ---
        if (str_contains($platform, 'shopee')) {
            $taxaFixa = 3.00; // Taxa de transação fixa por item

            // 1. Padrão (Sem programa de frete grátis)
            $percentual = 0.14; // 14%

            // 2. Com Programa de Frete Grátis (+6%)
            // Tipos aceitos: 'program', 'official', 'free_shipping'
            if (in_array($listingType, ['program', 'official', 'free_shipping'])) {
                $percentual = 0.20; // 14% + 6%
            }

            $valorComissao = $price * $percentual;

            // Aplicação dos Tetos (Caps) da Shopee (Opcional, mas preciso)
            // Teto da Comissão Padrão (14%) = R$ 100 + Teto da Taxa de Serviço (6%) = R$ 10
            // Para simplificar a precificação e garantir margem, não aplicamos o teto no cálculo de sugestão,
            // mas aqui no cálculo de custo real, poderíamos aplicar. 
            // Vamos manter simples (percentual cheio) para segurança do vendedor.

            return [
                'fixed_fee' => $taxaFixa,
                'commission_fee' => $valorComissao,
                'total_fee' => $taxaFixa + $valorComissao
            ];
        }

        // --- LÓGICA MERCADO LIVRE ---
        if (str_contains($platform, 'mercadolibre')) {
            $taxaFixa = 0.00;
            if ($price < 79.00) {
                $taxaFixa = 6.00;
            }

            $percentual = 0.18; // Premium
            if ($listingType === 'gold_special' || $listingType === 'classic') {
                $percentual = 0.13; // Clássico
            }

            $valorComissao = $price * $percentual;

            return [
                'fixed_fee' => $taxaFixa,
                'commission_fee' => $valorComissao,
                'total_fee' => $taxaFixa + $valorComissao
            ];
        }

        // Padrão Genérico
        $rate = 0.12;
        return [
            'fixed_fee' => 0,
            'commission_fee' => $price * $rate,
            'total_fee' => $price * $rate
        ];
    }

    public function calculateTaxes(Company $company, $amount)
    {
        $aliquota = ($company->default_tax ?? 10) / 100;
        return $amount * $aliquota;
    }

    public function processOrder(Company $company, array $orderData, array $items)
    {
        return DB::transaction(function () use ($company, $orderData, $items) {
            $totalVenda = $orderData['total_amount'];
            $imposto = $this->calculateTaxes($company, $totalVenda);

            $taxasMkt = $orderData['cost_tax_platform'] ?? 0;
            $taxaFixa = $orderData['cost_tax_fixed'] ?? 0;
            $cmvTotal = 0;
            $frete = $orderData['cost_shipping_seller'] ?? 0;
            $marketing = 0;

            $calcularTaxas = !isset($orderData['cost_tax_platform']) || $orderData['cost_tax_platform'] == 0;

            foreach ($items as $item) {
                $product = Product::where('company_id', $company->id)->where('sku', $item['sku'])->first();
                $custoUnitario = $product ? $product->cost_price : ($item['unit_cost'] ?? 0);
                $cmvTotal += $custoUnitario * $item['quantity'];

                if ($calcularTaxas) {
                    $listingType = $orderData['listing_type'] ?? 'standard';
                    $platform = $orderData['platform_slug'] ?? 'generic';

                    $fees = $this->calculateMarketplaceFees($item['unit_price'], $listingType, $platform);
                    $taxasMkt += $fees['commission_fee'] * $item['quantity'];
                    $taxaFixa += $fees['fixed_fee'] * $item['quantity'];
                }
            }

            $totalDespesas = $cmvTotal + $taxasMkt + $taxaFixa + $frete + $imposto + $marketing;
            $lucroLiquido = $totalVenda - $totalDespesas;
            $margemContrib = $totalVenda > 0 ? ($lucroLiquido / $totalVenda) * 100 : 0;

            $order = Order::updateOrCreate(
            ['company_id' => $company->id, 'external_id' => $orderData['external_id'], 'integration_id' => $orderData['integration_id']],
            [
                'status' => $orderData['status'],
                'date_created' => $orderData['date_created'],
                'total_amount' => $totalVenda,
                'cost_products' => $cmvTotal,
                'cost_tax_platform' => $taxasMkt,
                'cost_tax_fixed' => $taxaFixa,
                'cost_shipping_seller' => $frete,
                'cost_tax_fiscal' => $imposto,
                'cost_marketing' => $marketing,
                'net_profit' => $lucroLiquido,
                'contribution_margin' => $margemContrib,
                'updated_at' => now()
            ]
            );

            OrderItem::where('order_id', $order->id)->delete();
            foreach ($items as $item) {
                $prod = Product::where('company_id', $company->id)->where('sku', $item['sku'])->first();
                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $prod ? $prod->id : null,
                    'sku' => $item['sku'],
                    'title' => $item['title'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'unit_cost' => $prod ? $prod->cost_price : 0
                ]);
            }
            return $order;
        });
    }
}