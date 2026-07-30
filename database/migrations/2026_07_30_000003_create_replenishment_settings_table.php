<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Parâmetros do motor de Reposição Inteligente, por empresa — nada
 * hardcoded, editável na própria tela (lead time de importação Magazord/
 * Netshoes é bem diferente de compra nacional).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replenishment_settings')) {
            return;
        }

        Schema::create('replenishment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->unique();

            // Janela de cálculo: pesos da velocidade recente vs. histórica.
            $table->decimal('weight_v7', 4, 3)->default(0.500);
            $table->decimal('weight_v30', 4, 3)->default(0.300);
            $table->decimal('weight_v90', 4, 3)->default(0.200);

            // Cobertura alvo (dias) e estoque de segurança.
            $table->unsignedInteger('target_coverage_days')->default(30);
            $table->unsignedInteger('safety_days')->default(7)->comment('Fallback quando não há desvio-padrão suficiente (produto novo)');
            $table->decimal('service_level_z', 4, 2)->default(1.65)->comment('Z-score do nível de serviço (1.65 ~= 95%)');

            // Limiares de classificação.
            $table->unsignedInteger('excess_threshold_days')->default(120);
            $table->unsignedInteger('dead_stock_days')->default(90);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_settings');
    }
};
