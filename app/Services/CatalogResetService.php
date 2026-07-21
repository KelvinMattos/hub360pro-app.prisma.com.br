<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Limpeza de emergência do catálogo e vendas — remove produtos, dados de
 * produto e pedidos, preservando usuários, empresas e configurações.
 *
 * Usado pelo comando `catalog:reset` e pela Zona de Perigo nas Configurações.
 */
class CatalogResetService
{
    /** Tabelas a esvaziar, se existirem (ordem segura para FKs desligadas). */
    public array $tables = [
        'order_items', 'order_notifications', 'orders',
        'product_channel_settings', 'product_medias', 'product_kits',
        'products_meli', 'master_products', 'products',
        'stock_movements', 'stock_history', 'listing_histories',
    ];

    /** Tabelas existentes com contagem de registros. */
    public function preview(): array
    {
        $out = [];
        foreach ($this->tables as $t) {
            if (Schema::hasTable($t)) {
                $out[] = ['table' => $t, 'count' => (int) DB::table($t)->count()];
            }
        }
        return $out;
    }

    /** Executa a limpeza. Retorna a lista de tabelas esvaziadas com o total removido. */
    public function reset(?string $actor = null): array
    {
        $preview = $this->preview();
        $total = array_sum(array_column($preview, 'count'));

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($preview as $row) {
                DB::table($row['table'])->truncate();
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Log::warning('Catálogo/vendas RESETADOS', [
            'actor' => $actor,
            'registros_removidos' => $total,
            'tabelas' => array_column($preview, 'table'),
        ]);

        return ['tables' => $preview, 'total' => $total];
    }
}
