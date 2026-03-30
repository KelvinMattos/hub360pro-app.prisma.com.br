<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Tabela de Taxas Multicanal (Baseada na Sheet TAXAS).
     * Centraliza comissões, impostos e regras de taxas fixas.
     */
    public function up(): void
    {
        Schema::create('marketplace_fees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            $table->string('platform')->index(); // LOJA, ML_CLASSIC, ML_PREMIUM, SHOPEE, etc.
            $table->decimal('commission_percent', 5, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);

            // Regras de taxas fixas dinâmicas (ex: ML < 79 -> 6.00)
            // Estrutura: [{"min": 0, "max": 78.99, "fee": 6.00}, ...]
            $table->json('fixed_fee_rules')->nullable();

            $table->timestamps();

            // Impedir duplicidade de plataforma para a mesma empresa
            $table->unique(['company_id', 'platform']);
        });

        /**
         * Histórico de Estoque e Custos (Baseada na Sheet PV ATUAL).
         * Mantém o rastreio de quando o produto entrou e seu custo médio.
         */
        Schema::create('stock_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->integer('quantity');
            $table->decimal('cost_price', 15, 2);
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        /**
         * Análise de Promoções (Baseada na Sheet CALCULO PROMO).
         * Armazena os cálculos otimizados de PE e Meta 15%.
         */
        Schema::create('promotion_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('marketplace_fee_id')->constrained('marketplace_fees')->onDelete('cascade');

            $table->decimal('current_price', 15, 2);
            $table->decimal('breakeven_price', 15, 2)->comment('Ponto de Equilíbrio');
            $table->decimal('target_price_15', 15, 2)->comment('Preço para 15% de Lucro');
            $table->decimal('suggested_promo_price', 15, 2)->nullable();

            $table->string('stock_performance_flag')->nullable()->comment('ex: +2 anos, 1-2 anos');
            $table->boolean('is_deficitary')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_analyses');
        Schema::dropIfExists('stock_history');
        Schema::dropIfExists('marketplace_fees');
    }
};
