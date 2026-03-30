<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookPayload;
use App\Jobs\ProcessWebhookJob;
use Illuminate\Http\Request;

/**
 * Controller de Alta Performance para recebimento de Webhooks.
 * Padrão: Queue-First (Salva e Despacha).
 */
class WebhookController extends Controller
{
    /**
     * Recebe notificações do Mercado Livre, Shopee, etc.
     */
    public function receive(Request $request, string $platform)
    {
        // 1. Persistência Imediata (Landing Zone)
        $webhook = WebhookPayload::create([
            'platform' => $platform,
            'external_resource_id' => $request->get('resource') ?? $request->get('id'),
            'payload' => $request->all(),
            'status' => 'pending'
        ]);

        // 2. Despacho para Fila (Assíncrono)
        ProcessWebhookJob::dispatch($webhook);

        // 3. Resposta ultra-rápida (HTTP 202 Accepted)
        return response()->json(['message' => 'Notification received and queued.'], 202);
    }
}
