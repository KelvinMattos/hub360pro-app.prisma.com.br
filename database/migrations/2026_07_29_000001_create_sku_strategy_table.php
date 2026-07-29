<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segmentação de SKU: uma linha por produto, recalculada diariamente pelo
 * job `sku:classify-strategy` (App\Services\SkuStrategyClassifier). A tela
 * de Segmentação só lê esta tabela — nunca calcula na hora do request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sku_strategy')) {
            return;
        }

        Schema::create('sku_strategy', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('product_id')->index();
            $table->string('sku', 100)->nullable()->index();

            // Papel de precificação: matriz margem x volume vs. mediana da empresa.
            $table->string('pricing_role', 20)->default('sem_dado');
            $table->decimal('margin_pct', 8, 2)->nullable();
            $table->integer('volume_30d')->default(0);

            // Ciclo de vida: tendência de venda 30/90/180 dias.
            $table->string('lifecycle_stage', 20)->default('sem_dado');
            $table->decimal('trend_30_90_pct', 8, 2)->nullable();
            $table->decimal('trend_90_180_pct', 8, 2)->nullable();

            // Saúde de estoque: cobertura, giro e aging combinados num índice 0-100.
            $table->unsignedTinyInteger('stock_health_index')->nullable();
            $table->decimal('stock_coverage_days', 8, 1)->nullable();
            $table->decimal('stock_turnover', 8, 3)->nullable();
            $table->integer('stock_aging_days')->nullable();

            // Posição competitiva: mesmas 4 categorias do MarketMonitorService.
            $table->string('competitive_position', 20)->default('desconhecido');
            $table->decimal('market_gap_pct', 8, 2)->nullable();
            $table->decimal('buybox_distance_pct', 8, 2)->nullable();

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sku_strategy');
    }
};
