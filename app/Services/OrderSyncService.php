<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\OrderNotification;
use App\Models\Integration;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Services\Adapters\MercadoLivreAdapter;
use App\Services\Strategies\MeliFeeSynchronizer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    protected $adapter;
    protected $feeSyncer;

    public function __construct(MercadoLivreAdapter $adapter, MeliFeeSynchronizer $feeSyncer)
    {
        $this->adapter = $adapter;
        $this->feeSyncer = $feeSyncer;
    }

    /**
     * Sincroniza um pedido pelo ID Externo (MLB...)
     * Usado pelo Webhook
     */
    public function syncByExternalId($externalId, Integration $integration)
    {
        // Busca ou cria o pedido "casca"
        $order = Order::firstOrCreate(
            ['external_id' => $externalId, 'company_id' => $integration->company_id],
            ['integration_id' => $integration->id, 'status' => 'pending', 'total_amount' => 0]
        );

        return $this->performDeepSync($order);
    }

    /**
     * Sincronização Profunda (Lógica Unificada)
     */
    public function performDeepSync(Order $order)
    {
        $integration = $order->integration;
        if (!$integration) throw new \Exception("Integração desconectada.");

        // 1. Busca Dados na API
        $orderData = $this->adapter->fetchSingleOrder($integration, $order->external_id);
        if (!$orderData) throw new \Exception("Pedido não encontrado no ML.");

        // Verifica mudança de status para notificar
        $oldStatus = $order->status;
        $newStatus = $orderData['status'];

        // 2. Atualiza Produtos e Taxas (Inteligência)
        foreach ($orderData['items'] as $itemData) {
            $product = Product::where('company_id', $order->company_id)
                ->where(function($q) use ($itemData) {
                    $q->where('sku', $itemData['sku'])
                      ->orWhere('external_id', $itemData['external_id']);
                })->first();

            if ($product && $product->category_id) {
                // Atualiza taxa do produto na API de Preços
                try {
                    $this->feeSyncer->syncProductFees($product);
                } catch (\Exception $e) {}
            }
            
            // Garante criação do Item do Pedido
            $uCost = ($product && $product->cost_price > 0) ? $product->cost_price : ($itemData['unit_price'] * 0.5);
            OrderItem::updateOrCreate(
                ['order_id' => $order->id, 'external_id' => $itemData['external_id']],
                [
                    'sku' => $itemData['sku'], 'title' => $itemData['title'], 
                    'quantity' => $itemData['quantity'], 'unit_price' => $itemData['unit_price'], 
                    'unit_cost' => $uCost, 'product_id' => $product ? $product->id : null
                ]
            );
        }
        
        $order->load('items.product.pricing');

        // 3. Cálculo de Taxas (Post-Sale Híbrido)
        $calculatedComm = $orderData['cost_fee_commission'];
        $calculatedFixed = $orderData['cost_fee_fixed'];

        // Se API de pedidos vier zerada, usa a Inteligência
        if ($calculatedComm == 0) {
            $totalComm = 0;
            $totalFixed = 0;
            foreach ($order->items as $item) {
                if ($item->product && $item->product->pricing && $item->product->pricing->meli_commission_percent > 0) {
                    $percent = $item->product->pricing->meli_commission_percent;
                    $itemTotal = $item->unit_price * $item->quantity;
                    $totalComm += $itemTotal * ($percent / 100);
                } else {
                    $totalComm += ($item->unit_price * $item->quantity) * 0.13; // Fallback
                }
                if ($item->unit_price < 79) $totalFixed += ($item->quantity * 6.00);
            }
            if ($totalComm > 0) $calculatedComm = $totalComm;
            if ($totalFixed > 0) $calculatedFixed = $totalFixed;
        }

        // 4. Lógica de Frete (Seller Cost)
        $shipmentData = $orderData['json_shipment'] ?? [];
        $shippingCost = 0;
        if (isset($shipmentData['shipping_option'])) {
            $listCost = (float)($shipmentData['shipping_option']['list_cost'] ?? 0);
            $buyerCost = (float)($shipmentData['shipping_option']['cost'] ?? 0);
            $shippingCost = max(0, $listCost - $buyerCost);
        } else {
            $shippingCost = $orderData['cost_fee_shipping'];
        }

        // 5. Consolida DRE
        $grossRevenue = $orderData['total_amount'];
        $platformFees = $calculatedComm + $calculatedFixed + $shippingCost + $orderData['cost_fee_ads'] + $orderData['cost_fee_taxes'];
        
        $company = $order->company;
        $globalTaxRate = $company->tax_rate ?? 6.00;
        $globalOpRate = $company->operational_cost_rate ?? 10.00;

        $fiscalTaxValue = $grossRevenue * ($globalTaxRate / 100);
        $operationalCostValue = $grossRevenue * ($globalOpRate / 100);
        
        $currentCmv = 0;
        foreach ($order->items as $item) {
             $currentCmv += ($item->unit_cost * $item->quantity);
        }

        $contributionMargin = $grossRevenue - $currentCmv - $platformFees - $fiscalTaxValue;
        $netProfit = $contributionMargin - $operationalCostValue;

        // 6. Persistência
        $order->update(array_merge($orderData, [
            'cost_products' => $currentCmv,
            'cost_tax_platform' => $platformFees,
            'cost_tax_fiscal' => $fiscalTaxValue,
            'cost_operational' => $operationalCostValue,
            'contribution_margin' => $contributionMargin,
            'net_profit' => $netProfit,
            'cost_fee_commission' => $calculatedComm,
            'cost_fee_fixed' => $calculatedFixed,
            'cost_fee_shipping' => $shippingCost,
            'json_order' => $orderData['json_order'],
            'json_shipment' => $orderData['json_shipment'],
            'json_payments' => $orderData['json_payments'],
            'json_items' => $orderData['json_items'],
            'logistic_type' => $orderData['logistic_type'],
            'shipping_mode' => $orderData['shipping_mode'],
            'buyer_nickname' => $orderData['buyer_nickname']
        ]));

        // 7. Geração de Notificação
        if ($oldStatus !== $newStatus) {
            $this->createStatusNotification($order, $oldStatus, $newStatus);
        }

        return $order;
    }

    private function createStatusNotification($order, $old, $new)
    {
        $titles = [
            'paid' => 'Venda Aprovada!',
            'packed' => 'Pronto para Envio',
            'shipped' => 'Pedido Enviado',
            'delivered' => 'Pedido Entregue',
            'cancelled' => 'Venda Cancelada'
        ];

        $types = [
            'paid' => 'success',
            'shipped' => 'info',
            'cancelled' => 'danger'
        ];

        $title = $titles[$new] ?? "Status: $new";
        $type = $types[$new] ?? 'info';
        
        OrderNotification::create([
            'company_id' => $order->company_id,
            'order_id' => $order->id,
            'external_id' => $order->external_id,
            'title' => $title,
            'message' => "O pedido #{$order->external_id} mudou de '{$old}' para '{$new}'.",
            'type' => $type,
            'is_read' => false
        ]);
    }
}