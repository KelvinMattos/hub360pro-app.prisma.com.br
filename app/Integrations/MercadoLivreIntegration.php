<?php

namespace App\Integrations;

class MercadoLivreIntegration extends AbstractMarketplace
{
    public function getName(): string
    {
        return 'Mercado Livre';
    }

    public function getIcon(): string
    {
        return 'heroicon-o-hand-raised';
    }

    public function authenticate(): void
    {
    // Lógica de OAuth2 do Mercado Livre
    }

    public function syncProducts(): void
    {
    // Busca produtos via API /users/{user_id}/items/search
    }

    public function syncOrders(): void
    {
    // Busca ordens recentes /orders/search
    }

    public function updateStock(string $externalProductId, int $quantity): bool
    {
        // PUT /items/{item_id} com available_quantity
        return true;
    }
}