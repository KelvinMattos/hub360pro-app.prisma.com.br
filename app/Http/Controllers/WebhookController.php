<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Integration;
use App\Services\OrderSyncService;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request, $platform, OrderSyncService $syncService)
    {
        try {
            if ($platform !== 'mercadolibre') return response()->json(['status' => 'ignored']);

            $data = $request->all();
            Log::info("Webhook Meli Recebido:", $data);

            // Filtra apenas tópicos de pedidos
            // orders_v2 é o tópico atual, mas 'orders' pode aparecer em sistemas legados
            if (!isset($data['topic']) || !in_array($data['topic'], ['orders_v2', 'orders', 'shipments'])) {
                return response()->json(['status' => 'ignored_topic']);
            }

            // Identifica o User ID para saber de qual empresa é
            $userId = $data['user_id'];
            $integration = Integration::where('seller_id', $userId)
                ->where('platform', 'mercadolibre')
                ->first();

            if (!$integration) {
                return response()->json(['status' => 'integration_not_found'], 404);
            }

            // Extrai o ID do recurso (ex: /orders/12345 -> 12345)
            $resource = $data['resource'];
            $parts = explode('/', $resource);
            $externalId = end($parts);

            // Processa Pedidos
            if (str_contains($data['topic'], 'order')) {
                // Sincroniza Instantaneamente (Idealmente isso iria para uma Queue/Job em alta escala)
                // Como você pediu "instantâneo", rodamos direto aqui.
                $syncService->syncByExternalId($externalId, $integration);
                return response()->json(['status' => 'synced']);
            }

            return response()->json(['status' => 'ok']);

        } catch (\Exception $e) {
            Log::error("Webhook Error: " . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }
}