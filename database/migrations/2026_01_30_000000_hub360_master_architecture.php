<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Contas Mercado Livre (Multicontas)
        if (!Schema::hasTable('ml_accounts')) {
            Schema::create('ml_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('nickname');
                $table->string('seller_id')->unique();
                $table->string('access_token')->nullable();
                $table->timestamps();
            });
        }

        // 2. Master SKU (O Cérebro do Estoque)
        if (!Schema::hasTable('master_skus')) {
            Schema::create('master_skus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('sku')->index(); // SKU Único (Ex: CAMISETA-P)
                $table->string('ean')->nullable()->index();
                $table->decimal('cost_price', 10, 2)->default(0);
                $table->integer('lead_time')->default(15)->comment('Dias para chegar do fornecedor');
                $table->integer('safety_stock')->default(5)->comment('Estoque mínimo');
                $table->timestamps();
            });
        }

        // 3. Atualização de Produtos (Vínculo com Conta e Master)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'master_sku_id')) {
                $table->foreignId('master_sku_id')->nullable()->comment('Liga vários anúncios ao mesmo produto físico');
            }
            if (!Schema::hasColumn('products', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
            // Garante campos de gestão
            if (!Schema::hasColumn('products', 'stock_quantity')) $table->integer('stock_quantity')->default(0);
        });

        // 4. Atualização de Pedidos (Financeiro Real)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) {
                $table->decimal('total_paid_amount', 15, 2)->default(0)->comment('Valor Bruto');
            }
            if (!Schema::hasColumn('orders', 'ml_fee_amount')) {
                $table->decimal('ml_fee_amount', 10, 2)->default(0)->comment('Tarifa ML');
            }
            if (!Schema::hasColumn('orders', 'net_shipping_cost')) {
                $table->decimal('net_shipping_cost', 10, 2)->default(0)->comment('Custo Real Frete');
            }
            if (!Schema::hasColumn('orders', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
        });
    }

    public function down() {}
};