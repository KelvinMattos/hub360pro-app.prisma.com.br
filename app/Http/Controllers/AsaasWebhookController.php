<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessAsaasWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class AsaasWebhookController extends Controller
{
    /**
     * Receber eventos do Asaas e despachar para fila
     */
    public function handle(Request $request): JsonResponse
    {
        // Validar Token do Webhook (Opcional: implementar via Middleware futuramente)

        $payload = $request->all();

        // Despachar processamento para fila para resposta rápida ao Asaas (200 OK)
        ProcessAsaasWebhookJob::dispatch($payload);

        return response()->json(['status' => 'success']);
    }
}