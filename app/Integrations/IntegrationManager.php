<?php

namespace App\Integrations;

use App\Models\Integration;
use Exception;

class IntegrationManager
{
    /**
     * Resolve a implementação correta para uma integração
     */
    public function make(Integration $integration): AbstractMarketplace
    {
        $platform = strtolower($integration->platform);

        return match ($platform) {
            'mercadolibre', 'mercado_livre', 'mercadolivre' => new MercadoLivreIntegration($integration),
            // 'shopee' => new ShopeeIntegration($integration),
            default => throw new Exception("Plataforma [{$integration->platform}] não suportada."),
        };
    }
}