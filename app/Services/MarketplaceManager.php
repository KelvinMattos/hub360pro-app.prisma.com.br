<?php

namespace App\Services;

use App\Models\Integration;
use App\Services\Adapters\MercadoLivreAdapter;
use App\Services\Adapters\ShopeeAdapter;
use App\Services\Adapters\AmazonAdapter;
use Exception;

class MarketplaceManager
{
    public function adapter(Integration $credential)
    {
        $platform = strtolower($credential->platform);

        return match ($platform) {
            'mercadolibre', 'mercado_livre', 'mercadolivre' => new MercadoLivreAdapter(),
            'shopee', 'simulador_shopee'   => new ShopeeAdapter(),
            'amazon'                       => new AmazonAdapter(),
            'simulador_tiktok'             => new MercadoLivreAdapter(), // Placeholder se usarem a mesma base ou se for apenas rascunho
            'simulador_magazord'           => new MercadoLivreAdapter(), 
            'simulador_bling'              => new MercadoLivreAdapter(),
            default => throw new Exception("Marketplace [{$credential->platform}] não suportado."),
        };
    }
}
