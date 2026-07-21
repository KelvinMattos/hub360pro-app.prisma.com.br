<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preço promocional (Por) do produto — alimentado pela "Consulta Dinâmica –
 * Produtos com Desconto". O sale_price passa a guardar o preço cheio (De).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'promotional_price')) {
                $table->decimal('promotional_price', 15, 2)->nullable()->after('sale_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'promotional_price')) {
                $table->dropColumn('promotional_price');
            }
        });
    }
};
