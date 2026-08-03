<?php

namespace App\Support;

/**
 * Canais com importador nativo de Vendas (arquivo de exportação própria do
 * canal, nível de pedido/item, grava em orders+order_items) — cada um
 * suporta múltiplas contas via `sales_channel_accounts` (pedido do cliente
 * 03/08/2026: "posso ter duas contas distintas do mercado livre, 3 contas
 * na shopee"). Netshoes já tem importador próprio (NetshoesImportController)
 * e não entra aqui por ora — o cliente não pediu multi-conta pra ele.
 */
class OrderImportChannels
{
    public const LABELS = [
        'mercado_livre' => 'Mercado Livre',
        'shopee' => 'Shopee',
        'centauro' => 'Centauro',
        'renner' => 'Renner',
        'magalu' => 'Magazine Luiza',
    ];

    public static function label(string $channel): string
    {
        return self::LABELS[$channel] ?? $channel;
    }

    public static function all(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isValid(string $channel): bool
    {
        return isset(self::LABELS[$channel]);
    }
}
