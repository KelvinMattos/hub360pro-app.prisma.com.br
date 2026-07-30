<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reposição Inteligente precisa de lote mínimo (moq) e múltiplo de compra do
 * fornecedor pra arredondar a quantidade sugerida — não existiam no schema.
 * `lead_time`/`safety_stock` já existiam (ver migrations de 2026-01) e
 * continuam sendo a fonte por produto, com default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'moq')) {
                $table->unsignedInteger('moq')->default(1)->comment('Quantidade mínima de compra do fornecedor');
            }
            if (!Schema::hasColumn('products', 'purchase_multiple')) {
                $table->unsignedInteger('purchase_multiple')->default(1)->comment('Múltiplo de compra (ex.: caixa com 12 un)');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'purchase_multiple')) {
                $table->dropColumn('purchase_multiple');
            }
            if (Schema::hasColumn('products', 'moq')) {
                $table->dropColumn('moq');
            }
        });
    }
};
