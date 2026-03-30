<?php

namespace App\Jobs;

use App\Integrations\IntegrationManager;
use App\Models\Integration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;

class SyncMarketplaceProductsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        protected Integration $integration
        )
    {
    }

    public function handle(IntegrationManager $manager): void
    {
        try {
            $adapter = $manager->make($this->integration);
            $adapter->syncProducts();
        }
        catch (Exception $e) {
            // Logar erro ou disparar notificação push via Filament
            report($e);
            throw $e;
        }
    }
}