<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAsaasWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        protected array $payload
        )
    {
    }

    public function handle(): void
    {
        $event = $this->payload['event'] ?? null;

        Log::info("Asaas Webhook Event: {$event}", ['payload' => $this->payload]);

        switch ($event) {
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_CONFIRMED':
                $this->handlePaymentConfirmed();
                break;

            case 'PAYMENT_OVERDUE':
                $this->handlePaymentOverdue();
                break;

            default:
                Log::warning("Evento Asaas não tratado: {$event}");
        }
    }

    protected function handlePaymentConfirmed(): void
    {
        $asaasPaymentId = $this->payload['payment']['id'];
        Log::info("Pagamento Asaas Confirmado: {$asaasPaymentId}");

    // Lógica para encontrar o pedido correspondente e atualizar status
    }

    protected function handlePaymentOverdue(): void
    {
        Log::info("Pagamento Asaas Vencido");
    }
}