<?php

namespace App\Observers;

use App\Models\Product;
use App\Services\StockSyncService;

class ProductObserver
{
    protected StockSyncService $stockSyncService;

    public function __construct(StockSyncService $stockSyncService)
    {
        $this->stockSyncService = $stockSyncService;
    }

    /**
     * Handle the Product "updated" event.
     */
    public function updated(Product $product): void
    {
        // If stock changed, sync to marketplaces
        if ($product->isDirty('stock')) {
            $this->stockSyncService->syncStockToAllMarketplaces($product);
        }
    }
}
