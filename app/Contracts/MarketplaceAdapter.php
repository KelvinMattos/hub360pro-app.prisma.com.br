<?php

namespace App\Contracts;

use App\Models\Integration;

interface MarketplaceAdapter
{
    public function checkConnection(Integration $credential): bool;
    
    public function fetchOrders(Integration $credential, $limit = 50);
    
    public function fetchProducts(Integration $credential);
    
    public function getShipmentLabel(Integration $credential, string $shippingId);
    
    public function updatePrice(Integration $credential, $external_id, $price, $promoPrice = null);
    
    public function updateStock(Integration $credential, string $externalId, int $stock);

    // High Level Features
    public function fetchQuestions(Integration $credential);
    
    public function answerQuestion(Integration $credential, string $questionId, string $text);
    
    public function fetchMessages(Integration $credential, string $orderId);
    
    public function sendMessage(Integration $credential, string $orderId, string $text);

    public function fetchHistoricalBatch(Integration $credential, $olderThanDate);
}