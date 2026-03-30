<?php

namespace App\Services;

use App\Models\Integration;
use App\Models\MarketplaceMessage;
use Illuminate\Support\Facades\Log;

class MarketplaceMessageService
{
    protected MarketplaceManager $manager;

    public function __construct(MarketplaceManager $manager)
    {
        $this->manager = $manager;
    }

    public function syncMessages(Integration $credential, string $orderId)
    {
        try {
            $adapter = $this->manager->adapter($credential);
            $messages = $adapter->fetchMessages($credential, $orderId);

            foreach ($messages as $msg) {
                MarketplaceMessage::updateOrCreate(
                    ['external_id' => $msg['id'], 'company_id' => $credential->company_id],
                    [
                        'integration_id' => $credential->id,
                        'order_id' => $msg['order_id'],
                        'sender_id' => $msg['from']['id'],
                        'text' => $msg['text'],
                        'direction' => $msg['from']['id'] == $credential->external_user_id ? 'outbound' : 'inbound',
                        'status' => 'sent',
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error("Error syncing messages for order {$orderId}: " . $e->getMessage());
        }
    }

    public function sendMessage(Integration $credential, string $orderId, string $text)
    {
        $adapter = $this->manager->adapter($credential);
        return $adapter->sendMessage($credential, $orderId, $text);
    }
}
