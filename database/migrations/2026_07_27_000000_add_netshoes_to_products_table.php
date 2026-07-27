<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados do canal Netshoes por produto (sobreposição de canal — NÃO altera o
 * catálogo, que continua vindo do Magazord). Alimentados pelos exports "Portal"
 * (produtos) e "INVENTORY" (estoque) da Netshoes, cruzados pelo `sku` do produto.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'netshoes_sku')) {
                // SKU do marketplace Netshoes (coluna "SKU Netshoes") — usado no cruzamento
                // Buy Box (SKU Netshoes -> ID Sku interno) do Cálculo Promo.
                $table->string('netshoes_sku')->nullable()->index()->after('sku');
            }
            if (!Schema::hasColumn('products', 'netshoes_price')) {
                $table->decimal('netshoes_price', 12, 2)->nullable();      // "Preço Por"
            }
            if (!Schema::hasColumn('products', 'netshoes_price_from')) {
                $table->decimal('netshoes_price_from', 12, 2)->nullable(); // "Preço De"
            }
            if (!Schema::hasColumn('products', 'netshoes_stock')) {
                $table->integer('netshoes_stock')->nullable();             // "Quantidade disponível"
            }
            if (!Schema::hasColumn('products', 'netshoes_status')) {
                $table->string('netshoes_status')->nullable();             // Ativo / Inativo
            }
            if (!Schema::hasColumn('products', 'netshoes_synced_at')) {
                $table->timestamp('netshoes_synced_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach ([
                'netshoes_sku', 'netshoes_price', 'netshoes_price_from',
                'netshoes_stock', 'netshoes_status', 'netshoes_synced_at',
            ] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
