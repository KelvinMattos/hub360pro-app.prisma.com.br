<?php

namespace App\Support;

/**
 * Plataformas de ADS suportadas pelo módulo de monitoramento (pedido do
 * cliente 04/08/2026: cruzar gasto de anúncio com receita real de venda via
 * UTM). Suporta múltiplas contas por plataforma, mesmo padrão de
 * `OrderImportChannels`/`sales_channel_accounts`.
 */
class AdPlatforms
{
    public const LABELS = [
        'google_ads' => 'Google Ads',
        'meta_ads' => 'Meta Ads',
    ];

    public static function label(string $platform): string
    {
        return self::LABELS[$platform] ?? $platform;
    }

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $platform): bool
    {
        return isset(self::LABELS[$platform]);
    }
}
