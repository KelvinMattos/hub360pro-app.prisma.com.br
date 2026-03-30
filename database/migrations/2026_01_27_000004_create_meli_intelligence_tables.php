<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. EXTENSÃO DO PRODUTO (Dados específicos da API Meli)
        Schema::create('products_meli', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('meli_item_id')->unique()->nullable(); // Ex: MLB12345678
            $table->string('listing_type_id')->nullable(); // gold_pro, gold_special
            $table->string('category_id')->nullable(); // Ex: MLB1234
            $table->decimal('health_score', 3, 2)->nullable(); // 0.00 a 1.00
            $table->string('shipping_mode')->nullable(); // me2, fulfillment, flex
            $table->string('logistic_type')->nullable(); // cross_docking, xd_drop_off
            $table->json('attributes_snapshot')->nullable(); // Snapshot técnico (Ficha técnica)
            $table->timestamps();
        });

        // 2. MONITOR DE CONCORRÊNCIA (War Room)
        Schema::create('competitor_monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->string('competitor_item_id'); // ID do anúncio rival (MLB...)
            $table->string('competitor_seller_name')->nullable();
            $table->decimal('last_tracked_price', 10, 2)->nullable();
            $table->boolean('is_active')->default(true); // Se o rival pausou
            $table->decimal('price_gap_percent', 6, 2)->nullable(); // Diferença % (pode ser negativa)
            $table->timestamp('last_check_at')->nullable();
            $table->timestamps();
            
            // Índice composto para busca rápida no dashboard
            $table->index(['product_id', 'competitor_item_id']);
        });

        // 3. ANALYTICS (Histórico Diário de Performance)
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            $table->date('date');
            $table->integer('visits')->default(0);
            $table->integer('orders')->default(0); // Vendas do dia
            $table->decimal('gross_revenue', 10, 2)->default(0); // Faturamento bruto do dia
            $table->integer('questions')->default(0);
            $table->decimal('conversion_rate', 5, 2)->default(0);
            $table->timestamps();

            // Garante que só existe um registro por produto por dia
            $table->unique(['product_id', 'date']); 
        });

        // 4. CÉREBRO FINANCEIRO (Configurações de Precificação)
        Schema::create('pricing_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
            
            // Variáveis do Usuário
            $table->decimal('cost_price', 10, 2)->default(0);      // Custo Fab/Compra
            $table->decimal('tax_percentage', 5, 2)->default(0);   // Imposto Nota (Simples/Lucro Real)
            $table->decimal('desired_margin', 5, 2)->default(20);  // Margem Alvo (Ex: 20%)
            $table->decimal('fixed_costs_extra', 10, 2)->default(0); // Embalagem/Fita/Etiqueta
            
            // Variáveis Automatizadas (Cache do ML)
            $table->decimal('meli_commission_percent', 5, 2)->default(0); // Taxa da categoria (14%, etc)
            $table->decimal('shipping_cost', 10, 2)->default(0);   // Custo Frete Real (se houver)
            
            $table->timestamps();
        });

        // 5. ESTRUTURA DE KITS (Relacionamento N:N)
        Schema::create('product_kits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_product_id')->constrained('products')->onDelete('cascade'); // O Anúncio do Kit (MLB...)
            $table->foreignId('child_product_id')->constrained('products')->onDelete('cascade');  // O Item Físico (SKU Interno)
            $table->integer('quantity')->default(1);
            $table->timestamps();
            
            // Evita duplicidade do mesmo item no mesmo kit
            $table->unique(['parent_product_id', 'child_product_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('product_kits');
        Schema::dropIfExists('pricing_configs');
        Schema::dropIfExists('analytics_daily');
        Schema::dropIfExists('competitor_monitors');
        Schema::dropIfExists('products_meli');
    }
};