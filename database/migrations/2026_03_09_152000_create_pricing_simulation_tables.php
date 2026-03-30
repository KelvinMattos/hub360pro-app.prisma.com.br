<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Tabela de Cenários para o Simulador 360 PRO.
     * Permite agrupar diversas simulações de "E se..." (What-If Analysis).
     */
    public function up(): void
    {
        Schema::create('pricing_simulation_scenarios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('name')->comment('Ex: Black Friday, Reajuste Frete');
            $table->text('description')->nullable();
            $table->boolean('is_applied')->default(false)->comment('Indica se este cenário foi movido para produção');
            $table->timestamps();
        });

        Schema::create('pricing_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scenario_id')->constrained('pricing_simulation_scenarios')->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');

            // Dados de Entrada (Simulados)
            $table->decimal('cost_price', 15, 2)->default(0);
            $table->decimal('shipping_cost', 10, 2)->default(0);
            $table->decimal('fixed_fee', 10, 2)->default(0)->comment('Taxa fixa por venda (ex: R$ 5,00)');

            // Taxas Percentuais
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('desired_margin_percent', 5, 2)->default(0);

            // Resultado Calculado
            $table->decimal('suggested_price', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pricing_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');

            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);

            $table->string('change_source')->default('manual')->comment('manual, simulation, api');
            $table->foreignId('simulation_id')->nullable()->constrained('pricing_simulations')->onDelete('set null');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_history');
        Schema::dropIfExists('pricing_simulations');
        Schema::dropIfExists('pricing_simulation_scenarios');
    }
};
