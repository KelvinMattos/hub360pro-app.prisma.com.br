<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\MarketplaceFee;
use App\Models\PromotionAnalysis;
use App\Services\Pricing\PricingIntelligenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessPromotionAnalysisJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $companyId;

    public function __construct(int $companyId)
    {
        $this->companyId = $companyId;
    }

    public function handle(PricingIntelligenceService $pricingService)
    {
        $products = Product::where('company_id', $this->companyId)->with('stockHistory')->get();
        $fees = MarketplaceFee::where('company_id', $this->companyId)->get();

        foreach ($products as $product) {
            foreach ($fees as $fee) {
                $cost = $product->cost_price ?? ($product->stockHistory->last()->cost_price ?? 0);
                if ($cost <= 0)
                    continue;

                $breakeven = $pricingService->calculateBreakEven($cost, $fee);
                $target15 = $pricingService->calculateTargetPrice($cost, $fee, 0.15);

                $daysInStock = $product->stockHistory->last() ? $product->stockHistory->last()->days_in_stock : 0;
                $suggested = $pricingService->suggestPromotionPrice($cost, $product->base_price, $fee, $daysInStock);

                PromotionAnalysis::updateOrCreate(
                [
                    'product_id' => $product->id,
                    'marketplace_fee_id' => $fee->id
                ],
                [
                    'current_price' => $product->base_price,
                    'breakeven_price' => $breakeven,
                    'target_price_15' => $target15,
                    'suggested_promo_price' => $suggested,
                    'stock_performance_flag' => $product->stockHistory->last() ? $product->stockHistory->last()->performance_flag : 'Recente',
                    'is_deficitary' => $suggested < $breakeven
                ]
                );
            }
        }
    }
}
