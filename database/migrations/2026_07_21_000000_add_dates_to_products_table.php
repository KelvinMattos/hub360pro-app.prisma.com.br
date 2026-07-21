<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Datas do produto (Consulta de Derivação do Magazord) usadas para calcular
 * o tempo de estoque e apoiar decisões de precificação.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'launched_at')) {
                $table->date('launched_at')->nullable()->after('status'); // Data de Lançamento
            }
            if (!Schema::hasColumn('products', 'catalog_updated_at')) {
                $table->timestamp('catalog_updated_at')->nullable()->after('launched_at'); // Data Atualização Produto
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['launched_at', 'catalog_updated_at'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
