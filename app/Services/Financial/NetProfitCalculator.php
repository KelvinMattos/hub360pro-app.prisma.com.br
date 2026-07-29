<?php

namespace App\Services\Financial;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lucro líquido do pedido:
 *
 *   net_profit = total_amount − (comissão + imposto + frete + taxa fixa + CMV)
 *              = total_amount − (cost_fee_commission + cost_fee_taxes
 *                                + cost_fee_shipping + cost_fee_fixed + cost_products)
 *
 * O mapeamento de colunas segue os comentários da migration original
 * (`2026_01_26_114504_add_detailed_costs_to_orders`):
 *   cost_fee_commission = comissão (% venda)
 *   cost_fee_fixed      = taxa fixa
 *   cost_fee_shipping   = frete pago pelo vendedor
 *   cost_fee_taxes      = impostos retidos (IIBB etc.)
 *   cost_products       = CMV
 *
 * Não inclui `cost_fee_ads` (Product Ads) — fora da fórmula pedida.
 *
 * Só lê/escreve colunas confirmadas no schema real (Schema::hasColumn) —
 * este serviço nunca deve tocar em coluna que não existe, ao contrário de
 * outros pontos do código (OrderSyncService/FinancialService) que hoje
 * gravam em colunas inexistentes (external_id, integration_id,
 * cost_tax_fiscal) — bug pré-existente, fora do escopo desta correção.
 */
class NetProfitCalculator
{
    private const REQUIRED_COLUMNS = [
        'total_amount', 'cost_fee_commission', 'cost_fee_taxes',
        'cost_fee_shipping', 'cost_fee_fixed', 'cost_products',
    ];

    /** Cálculo puro — sem tocar em banco. Base dos testes unitários. */
    public function calculateFromValues(
        float $totalAmount,
        float $commissionFee,
        float $taxFee,
        float $shippingFee,
        float $fixedFee,
        float $cmv
    ): float {
        $expenses = $commissionFee + $taxFee + $shippingFee + $fixedFee + $cmv;
        return round($totalAmount - $expenses, 2);
    }

    /** Calcula a partir de um model Order já carregado. */
    public function calculate(Order $order): float
    {
        return $this->calculateFromValues(
            (float) ($order->total_amount ?? 0),
            (float) ($order->cost_fee_commission ?? 0),
            (float) ($order->cost_fee_taxes ?? 0),
            (float) ($order->cost_fee_shipping ?? 0),
            (float) ($order->cost_fee_fixed ?? 0),
            (float) ($order->cost_products ?? 0)
        );
    }

    /**
     * Recalcula e persiste o lucro líquido de um pedido via query builder
     * (não passa pelo $fillable do Eloquent, que hoje referencia colunas
     * inexistentes em outros campos).
     */
    public function recalculateAndSave(Order $order): float
    {
        $netProfit = $this->calculate($order);
        DB::table('orders')->where('id', $order->id)->update(['net_profit' => $netProfit]);
        return $netProfit;
    }

    /** True se o schema atual tem todas as colunas necessárias ao cálculo. */
    public function schemaReady(): bool
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'net_profit')) {
            return false;
        }
        foreach (self::REQUIRED_COLUMNS as $col) {
            if (!Schema::hasColumn('orders', $col)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Recalcula em lote via query builder puro (usado pelo comando de
     * backfill). Retorna o número de linhas atualizadas.
     */
    public function backfillChunk(iterable $rows): int
    {
        $updated = 0;
        foreach ($rows as $row) {
            $netProfit = $this->calculateFromValues(
                (float) ($row->total_amount ?? 0),
                (float) ($row->cost_fee_commission ?? 0),
                (float) ($row->cost_fee_taxes ?? 0),
                (float) ($row->cost_fee_shipping ?? 0),
                (float) ($row->cost_fee_fixed ?? 0),
                (float) ($row->cost_products ?? 0)
            );
            DB::table('orders')->where('id', $row->id)->update(['net_profit' => $netProfit]);
            $updated++;
        }
        return $updated;
    }
}
