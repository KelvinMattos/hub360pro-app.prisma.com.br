<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Tabela de Cenários de Simulação (Simulador 360 PRO).
     * Armazena rascunhos estratégicos sem alterar o produto real.
     */
    public function up(): void
    {
        if (Schema::hasTable('pricing_simulations')) {
            // Se a tabela j existe, no faz nada ou loga
            return;
        }

        Schema::create('pricing_simulations', function (Blueprint $table) {
            $table->id();

            // Isolamento Multi-Tenant (Seguindo padrão do projeto: company_id)
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();

            // Rastreabilidade (Quem fez a simulação)
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Vínculo opcional com um produto real
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Identificação do Cenário
            $table->string('scenario_name');
            $table->string('marketplace_target')->nullable();

            // Entradas Financeiras
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('freight', 15, 2)->default(0);
            $table->decimal('fixed_fee', 15, 2)->default(0);
            $table->decimal('taxes_percent', 5, 2)->default(0);
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('margin_percent', 5, 2)->default(0);

            // Resultados Calculados (Snapshot)
            $table->decimal('suggested_price', 15, 2)->default(0);
            $table->decimal('contribution_margin_value', 15, 2)->default(0);

            // Controle de Estado
            $table->enum('status', ['draft', 'applied', 'archived'])->default('draft');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_simulations');
    }
};
