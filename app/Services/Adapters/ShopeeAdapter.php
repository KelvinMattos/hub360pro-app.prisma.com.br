<?php

namespace App\Services\Adapters;

use App\Contracts\MarketplaceAdapter;
use App\Models\Integration;
use Illuminate\Support\Facades\Log;

class ShopeeAdapter implements MarketplaceAdapter
{
    public function checkConnection(Integration $credential): bool
    {
        return !empty($credential->access_token);
    }

    public function fetchProducts(Integration $credential)
    {
        return [];
    }

    public function fetchOrders(Integration $credential, $limit = 50)
    {
        return [];
    }

    public function getShipmentLabel(Integration $credential, string $shippingId)
    {
        return null;
    }

    public function updatePrice(Integration $credential, string $externalId, float $price)
    {
        Log::info("Shopee: Updating price for {$externalId} to {$price}");
        return true;
    }

    public function updateStock(Integration $credential, string $externalId, int $stock)
    {
        Log::info("Shopee: Updating stock for {$externalId} to {$stock}");
        return true;
    }

    public function fetchQuestions(Integration $credential)
    {
        return [];
    }

    public function answerQuestion(Integration $credential, string $questionId, string $text)
    {
        Log::info("Shopee: Answering question {$questionId}");
        return true;
    }

    public function fetchMessages(Integration $credential, string $orderId)
    {
        return [];
    }

    public function sendMessage(Integration $credential, string $orderId, string $text)
    {
        Log::info("Shopee: Sending message to order {$orderId}");
        return true;
    }

    public function fetchHistoricalBatch(Integration $credential, $olderThanDate)
    {
        return [];
    }
}