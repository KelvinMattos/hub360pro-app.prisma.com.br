<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Camada de monitoramento de preço de mercado por produto (base do módulo de
 * competitividade estilo Hooklab). O `market_price` é o melhor preço do
 * concorrente/mercado no canal; alimentado por entrada manual/planilha hoje e,
 * futuramente, pela API do marketplace. O status (vendendo/perdendo/alerta/
 * desconhecido) é calculado a partir dele — não é persistido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'market_price')) {
                $table->decimal('market_price', 12, 2)->nullable();       // melhor preço do concorrente
            }
            if (!Schema::hasColumn('products', 'market_seller')) {
                $table->string('market_seller')->nullable();              // vendedor que está ganhando
            }
            if (!Schema::hasColumn('products', 'market_source')) {
                $table->string('market_source')->nullable();              // manual | import | ml_api
            }
            if (!Schema::hasColumn('products', 'market_checked_at')) {
                $table->timestamp('market_checked_at')->nullable();       // última verificação de mercado
            }
            if (!Schema::hasColumn('products', 'monitored')) {
                $table->boolean('monitored')->default(true);              // participa do monitoramento
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['market_price', 'market_seller', 'market_source', 'market_checked_at', 'monitored'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
