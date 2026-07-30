<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reposição Inteligente: uma linha por produto, pré-calculada pelo comando
 * `inventory:compute-replenishment` (App\Services\Inventory\ReplenishmentEngine).
 * A tela (/inventory/planning) só LÊ esta tabela, paginada e filtrada no
 * banco — nunca mais calcula 78 mil linhas na hora do request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replenishment_plan')) {
            return;
        }

        Schema::create('replenishment_plan', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->string('sku', 100)->nullable();
            $table->string('title', 500)->nullable();
            $table->string('brand', 150)->nullable();

            $table->integer('stock')->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);

            // Velocidade de venda (unidades/dia) por janela, já descontando dias
            // sem estoque quando detectável, e a ponderada final usada nas contas.
            $table->decimal('velocity_7', 10, 3)->default(0);
            $table->decimal('velocity_30', 10, 3)->default(0);
            $table->decimal('velocity_90', 10, 3)->default(0);
            $table->decimal('velocity_weighted', 10, 3)->default(0);
            $table->decimal('demand_stddev', 10, 3)->nullable()->comment('Desvio-padrão da demanda diária (janela de 30d)');

            $table->integer('lead_time_days')->default(15);
            $table->decimal('safety_stock', 10, 2)->default(0);
            $table->decimal('reorder_point', 10, 2)->default(0);
            $table->decimal('coverage_days', 10, 1)->nullable()->comment('Null = sem giro, nunca 999');
            $table->integer('suggested_qty')->default(0);

            $table->string('status', 20)->default('desconhecido');
            $table->string('abc_class', 1)->nullable();

            $table->decimal('revenue_30d', 12, 2)->default(0);
            $table->decimal('revenue_at_risk_30d', 12, 2)->default(0);
            $table->decimal('immobilized_value', 12, 2)->default(0);
            $table->decimal('priority_score', 14, 2)->default(0);

            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'product_id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'priority_score']);
            $table->index(['company_id', 'abc_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replenishment_plan');
    }
};
