<?php

namespace App\Jobs;

use App\Models\WebhookLog;
use App\Models\Order;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job de Processamento de Webhooks (Background Worker).
 */
class ProcessMarketplaceWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private $webhookLog;

    public function __construct(WebhookLog $webhookLog)
    {
        $this->webhookLog = $webhookLog;
    }

    /**
     * Executa o processamento pesado do webhook.
     */
    public function handle(): void
    {
        try {
            $this->webhookLog->update(['status' => 'processing']);

            $payload = $this->webhookLog->payload ?? [];
            $userId = $payload['user_id'] ?? null;

            if (!$userId) {
                $this->webhookLog->update(['status' => 'ignored', 'error_message' => 'No user_id found']);
                return;
            }

            // 1. Identifica a Integração e Empresa
            $integration = \App\Models\Integration::where('external_user_id', (string)$userId)
                ->where('platform', 'mercadolivre')
                ->first();

            if (!$integration) {
                $this->webhookLog->update(['status' => 'ignored', 'error_message' => "No integration found for user_id {$userId}"]);
                return;
            }

            // 2. Direcionamento por Tópico (Master Prompt: Orders, Items, Questions)
            $topic = $payload['topic'] ?? '';
            $resource = $payload['resource'] ?? '';

            Log::info("Webhook Meli [{$topic}] para Company: {$integration->company_id}");

            switch ($topic) {
                case 'orders':
                case 'orders_v2':
                    // Dispara Sincronização Imediata (Somente o dia atual)
                    \App\Jobs\SyncMercadoLivreOrdersJob::dispatch(
                        $integration->company_id,
                        now()->subDay()->toIso8601String(),
                        now()->toIso8601String()
                    );
                    break;
                case 'questions':
                    // Dispara Sincronização de Perguntas
                    // \App\Jobs\SyncMercadoLivreQuestionsJob::dispatch($integration->company_id);
                    break;
            }

            // Sucesso
            $this->webhookLog->update([
                'status' => 'processed',
                'company_id' => $integration->company_id,
                'processed_at' => now()
            ]);

        }
        catch (Exception $e) {
            Log::error("Erro no Webhook HUB360 [ID: {$this->webhookLog->id}]: " . $e->getMessage());

            $this->webhookLog->update([
                'status' => 'failed',
                'error_message' => $e->getMessage()
            ]);

            throw $e; // Re-lança para o sistema de retentativa da Queue
        }
    }
}
