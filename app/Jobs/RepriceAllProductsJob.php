<?php

namespace App\Jobs;

use App\Models\PriceRule;
use App\Services\RepricingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RepriceAllProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(RepricingService $service): void
    {
        PriceRule::where('is_active', true)->each(function ($rule) use ($service) {
            $service->applyRule($rule);
        });
    }
}
