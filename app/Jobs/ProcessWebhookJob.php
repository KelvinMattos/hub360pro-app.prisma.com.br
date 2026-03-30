<?php

namespace App\Jobs;

use App\Models\WebhookPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Exception;

/**
 * Job assíncrono para processar payloads de webhooks salvos em banco.
 */
class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $webhookPayload;

    /**
     * @param WebhookPayload $webhookPayload
     */
    public function __construct(WebhookPayload $webhookPayload)
    {
        $this->webhookPayload = $webhookPayload;
    }

    /**
     * Executa o processamento logicamente isolado por plataforma.
     */
    public function handle()
    {
        $this->webhookPayload->update(['status' => 'processing']);

        try {
            // Lógica de "Routing" de Webhook
            switch ($this->webhookPayload->platform) {
                case 'mercadolibre':
                    $this->processMercadoLivre($this->webhookPayload->payload);
                    break;
                case 'shopee':
                    $this->processShopee($this->webhookPayload->payload);
                    break;
                default:
                    throw new Exception("Plataforma [{$this->webhookPayload->platform}] não suportada.");
            }

            $this->webhookPayload->update([
                'status' => 'processed',
                'processed_at' => now()
            ]);

        }
        catch (Exception $e) {
            $this->webhookPayload->update([
                'status' => 'failed',
                'error_log' => $e->getMessage()
            ]);

            throw $e; // Permite o retry automático do Laravel Queue
        }
    }

    private function processMercadoLivre(array $payload)
    {
    // Aqui chamamos o OrderSyncService ou InventoryService existente
    // ex: app(\App\Services\MercadoLivreService::class)->handleWebhook($payload);
    }

    private function processShopee(array $payload)
    {
    // ex: app(\App\Services\ShopeeService::class)->handleWebhook($payload);
    }
}
