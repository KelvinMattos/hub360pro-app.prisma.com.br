<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\WebhookLog;
use App\Jobs\ProcessMarketplaceWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Controller de Alta Disponibilidade para Webhooks de Marketplaces.
 * Responde em milissegundos e delega o processamento para a fila.
 */
class MarketplaceWebhookController extends Controller
{
    /**
     * Recebe e enfileira a notificação do marketplace.
     */
    public function handle(Request $request, string $source)
    {
        // 1. Validação de Assinatura (Exemplo Genérico)
        // Em produção real, você usaria HMAC ou Tokens específicos de cada plataforma.
        if (!$request->hasHeader('x-hub360-signature')) {
        // Log::warning("Recebido webhook sem assinatura de: {$source}");
        }

        try {
            // 2. Persistência Bruta e Rápida (Landing Zone)
            $webhookLog = WebhookLog::create([
                'source' => $source,
                'event_type' => $request->get('topic', 'unknown'), // Ex: topic no Meli
                'payload' => $request->all(),
                'status' => 'pending',
            ]);

            // 3. Despacho para Processamento Assíncrono (Redis)
            ProcessMarketplaceWebhookJob::dispatch($webhookLog);

            // 4. Resposta Imediata (ACK)
            return response()->json([
                'status' => 'queued',
                'log_id' => $webhookLog->id
            ], 202);

        }
        catch (\Exception $e) {
            Log::critical("Erro ao enfileirar webhook de {$source}: " . $e->getMessage());
            return response()->json(['error' => 'Internal failure'], 500);
        }
    }
}
