<?php

namespace App\Actions\Financial;

use App\Models\Order;
use App\Services\Financial\FinancialProrationService;

/**
 * Action responsável por "Liquidar" o pedido financeiramente.
 * Transforma um pedido "Capturado" (Marketing) em "Faturado/Líquido" (Financeiro).
 */
class SettleOrderFinancialAction
{
    private $prorationService;

    public function __construct(FinancialProrationService $prorationService)
    {
        $this->prorationService = $prorationService;
    }

    /**
     * Marca o pedido como faturado e aplica o rateio de custos fixos do mês.
     * 
     * @param int $orderId
     * @return Order
     */
    public function execute(int $orderId): Order
    {
        $order = Order::findOrFail($orderId);

        // Calcula o custo fixo rateado para o mês do pedido
        $allocationPerOrder = $this->prorationService->calculateAllocationPerOrder(
            $order->company_id,
            $order->date_created
        );

        // Atualiza o status e o lucro líquido real (faturado)
        $order->update([
            'financial_status' => 'billed',
            'net_profit_faturado' => $order->net_profit - $allocationPerOrder
        ]);

        return $order;
    }
}
