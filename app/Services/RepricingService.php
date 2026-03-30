<?php

namespace App\Services;

use App\Models\PriceRule;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

class RepricingService
{
    protected MarketplaceManager $manager;

    public function __construct(MarketplaceManager $manager)
    {
        $this->manager = $manager;
    }

    public function applyRule(PriceRule $rule)
    {
        if (!$rule->is_active) return;

        try {
            // Logic to fetch competitor prices would go here
            // For now, we simulate a repricing adjustment
            $newPrice = $this->calculateNewPrice($rule);

            if ($newPrice) {
                // Get first active credential for the marketplace (simplified)
                $credential = Integration::where('company_id', $rule->company_id)
                    ->where('is_active', true)
                    ->where('platform', '!=', 'bling')
                    ->first();

                if ($credential) {
                    $adapter = $this->manager->adapter($credential);
                    $adapter->updatePrice($credential, $rule->marketplace_item_id, $newPrice);
                    
                    $rule->update(['last_applied_at' => now()]);
                    Log::info("Repriced item {$rule->marketplace_item_id} to {$newPrice}");
                }
            }
        } catch (\Exception $e) {
            Log::error("Repricing failed for rule {$rule->id}: " . $e->getMessage());
        }
    }

    protected function calculateNewPrice(PriceRule $rule)
    {
        // Mock logic: follow cheapest with a small value difference
        $competitorPrice = $rule->product->price * 0.95; // Simulated competitor
        
        $targetPrice = match($rule->strategy) {
            'follow_cheapest' => $competitorPrice - $rule->value,
            'fixed_difference' => $rule->product->price - $rule->value,
            'percentage_margin' => $rule->product->cost * (1 + ($rule->value / 100)),
            default => $rule->product->price
        };

        return max($rule->min_price, min($rule->max_price, $targetPrice));
    }
}
