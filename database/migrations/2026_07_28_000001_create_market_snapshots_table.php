<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico de competitividade: uma foto por produto a cada coleta.
 * Alimenta a evolução temporal, a aba "Otimizações recentes" e o cálculo de
 * ganho/perda de Buy Box ao longo do tempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('market_snapshots')) {
            return;
        }

        Schema::create('market_snapshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->decimal('our_price', 12, 2)->nullable();
            $table->decimal('market_price', 12, 2)->nullable();
            $table->string('market_seller')->nullable();
            $table->boolean('buybox_winner')->nullable();
            $table->integer('offers_count')->nullable();
            $table->string('source', 40)->nullable();   // scraper_netshoes | import | manual
            $table->timestamp('captured_at')->nullable()->index();
            $table->timestamps();

            $table->index(['product_id', 'captured_at']);
            $table->index(['company_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_snapshots');
    }
};
