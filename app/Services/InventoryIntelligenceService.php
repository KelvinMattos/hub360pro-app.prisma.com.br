<?php

namespace App\Services;

use App\Models\Product;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Carbon\Carbon;

class InventoryIntelligenceService
{
    protected ?int $companyId = null;

    public function forCompany(int $companyId): self
    {
        $this->companyId = $companyId;
        return $this;
    }

    /**
     * Calcula a previsão de falta de estoque (Stock Out) para os produtos da empresa.
     */
    public function getStockOutPredictions(int $companyId, int $limit = 5)
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Busca produtos ativos com seu estoque atual e média de vendas 30 dias
        $products = Product::where('company_id', $companyId)
            ->where('status', 'active')
            ->get();

        $predictions = [];

        foreach ($products as $product) {
            // Soma de quantidades vendidas nos últimos 30 dias
            $salesLast30Days = DB::table('order_items')
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->where('order_items.product_id', $product->id)
                ->where('orders.company_id', $companyId)
                ->where('orders.created_at', '>=', $thirtyDaysAgo)
                ->whereIn('orders.status', ['paid', 'shipped', 'delivered', 'accredited'])
                ->sum('order_items.quantity');

            $dailyVelocity = $salesLast30Days / 30;
            $currentStock = (int)$product->stock_quantity;

            if ($dailyVelocity > 0) {
                $daysOfStock = $currentStock / $dailyVelocity;
            } else {
                $daysOfStock = 999; // Estoque "infinito" se não vende nada
            }

            $predictions[] = [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'current_stock' => $currentStock,
                'daily_velocity' => round($dailyVelocity, 2),
                'days_remaining' => round($daysOfStock, 0),
                'status' => $this->getRiskStatus($daysOfStock),
                'suggested_reorder' => $this->calculateSuggestedReorder($dailyVelocity, $daysOfStock)
            ];
        }

        // Ordena por criticidade (menor days_remaining primeiro)
        return collect($predictions)
            ->where('days_remaining', '<', 30)
            ->sortBy('days_remaining')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * Retorna estatísticas agregadas de inventário para o planejamento (War Room).
     */
    public function getAggregatedInventoryStats(int $companyId): Collection
    {
        $thirtyDaysAgo = Carbon::now()->subDays(30);

        // Busca produtos ativos com estoque e performance de vendas
        return Product::where('company_id', $companyId)
            ->where('status', 'active')
            ->get()
            ->map(function ($product) use ($companyId, $thirtyDaysAgo) {
                // Soma de quantidades vendidas nos últimos 30 dias (pedidos confirmados/pagos)
                $salesLast30Days = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->where('order_items.product_id', $product->id)
                    ->where('orders.company_id', $companyId)
                    ->where('orders.created_at', '>=', $thirtyDaysAgo)
                    ->whereIn('orders.status', ['paid', 'shipped', 'delivered', 'accredited'])
                    ->sum('order_items.quantity');

                $dailyVelocity = $salesLast30Days / 30;
                $currentStock = (int)$product->stock_quantity;
                $daysOfStock = $dailyVelocity > 0 ? $currentStock / $dailyVelocity : 999;
                
                // Cálculos financeiros do inventário
                $inventoryValue = $currentStock * (float)$product->cost_price;
                $revenue30d = $salesLast30Days * (float)$product->sale_price;
                
                return (object) [
                    'id' => $product->id,
                    'name' => $product->name,
                    'sku' => $product->sku,
                    'stock' => $currentStock,
                    'velocity' => round($dailyVelocity, 2),
                    'days_remaining' => round($daysOfStock, 0),
                    'status' => $this->getRiskStatus($daysOfStock),
                    'investment_needed' => $daysOfStock < 15 ? ceil($dailyVelocity * 45 - $currentStock) * (float)$product->cost_price : 0,
                    'immobilized_value' => $inventoryValue,
                    'revenue_30d' => $revenue30d,
                    'lost_revenue' => $daysOfStock <= 0 ? ($dailyVelocity * 30) * (float)$product->sale_price : 0
                ];
            });
    }

    private function getRiskStatus(float $days)
    {
        if ($days <= 0) return 'critical';
        if ($days < 7) return 'at_risk';
        if ($days < 15) return 'warning';
        return 'healthy';
    }

    private function calculateSuggestedReorder(float $velocity, float $daysRemaining)
    {
        // Sugere repor para 45 dias de cobertura se estiver abaixo de 15 dias
        if ($daysRemaining < 15) {
            return ceil($velocity * 45);
        }
        return 0;
    }
}