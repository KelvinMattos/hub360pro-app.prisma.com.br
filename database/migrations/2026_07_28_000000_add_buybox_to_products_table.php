<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buy Box por produto — alimentado pelo scraper da Netshoes (busca pelo
 * netshoes_sku, que é universal entre sellers) ou por planilha.
 *
 * `buybox_winner` responde a pergunta central: estamos ganhando ou não?
 *   true  = a loja vencedora é a nossa
 *   false = outro seller está ganhando
 *   null  = ainda não sabemos (sem coleta)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'market_url')) {
                $table->string('market_url', 600)->nullable();      // link do anúncio
            }
            if (!Schema::hasColumn('products', 'market_offers_count')) {
                $table->integer('market_offers_count')->nullable(); // nº de sellers na disputa
            }
            if (!Schema::hasColumn('products', 'buybox_winner')) {
                $table->boolean('buybox_winner')->nullable()->index();
            }
            if (!Schema::hasColumn('products', 'market_error')) {
                $table->string('market_error', 300)->nullable();    // último erro de coleta
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('products')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            foreach (['market_url', 'market_offers_count', 'buybox_winner', 'market_error'] as $col) {
                if (Schema::hasColumn('products', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
