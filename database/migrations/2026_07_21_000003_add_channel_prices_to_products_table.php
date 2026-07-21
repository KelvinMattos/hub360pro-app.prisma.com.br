<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preços de venda por canal (Site, Shopee, Mercado Livre, Centauro, ...) do
 * modelo "Consulta Dinâmica – Custo x Preço de Venda". Guardados como JSON
 * para o Cálculo Promo trabalhar direto do banco, sem reimportar planilhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'channel_prices')) {
                $table->json('channel_prices')->nullable()->after('promotional_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'channel_prices')) {
                $table->dropColumn('channel_prices');
            }
        });
    }
};
