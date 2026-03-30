<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Atualização Blindada da Tabela ORDERS
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) {
                $table->decimal('total_paid_amount', 15, 2)->default(0)->comment('Valor bruto pago pelo comprador');
            }
            if (!Schema::hasColumn('orders', 'ml_fee_amount')) {
                $table->decimal('ml_fee_amount', 10, 2)->default(0)->comment('Comissão do ML');
            }
            if (!Schema::hasColumn('orders', 'shipping_list_cost')) {
                $table->decimal('shipping_list_cost', 10, 2)->default(0)->comment('Custo da etiqueta');
            }
            if (!Schema::hasColumn('orders', 'shipping_buyer_paid')) {
                $table->decimal('shipping_buyer_paid', 10, 2)->default(0)->comment('Quanto comprador pagou de frete');
            }
            if (!Schema::hasColumn('orders', 'net_shipping_cost')) {
                $table->decimal('net_shipping_cost', 10, 2)->default(0)->comment('Custo real de envio');
            }
            if (!Schema::hasColumn('orders', 'ml_account_id')) {
                $table->string('ml_account_id')->nullable()->index()->comment('ID da conta ML');
            }
        });

        // 2. Atualização Blindada da Tabela PRODUCTS
        Schema::table('products', function (Blueprint $table) {
            // Adiciona colunas de controle se não existirem
            if (!Schema::hasColumn('products', 'lead_time')) {
                $table->integer('lead_time')->default(15)->comment('Dias para reposição');
            }
            if (!Schema::hasColumn('products', 'safety_stock')) {
                $table->integer('safety_stock')->default(5)->comment('Estoque de segurança');
            }
            
            // Adiciona EAN se não existir (O índice já é criado na migração base)
            if (!Schema::hasColumn('products', 'ean')) {
                $table->string('ean')->nullable();
            }

            // Garante que a coluna SKU seja nullable e aplica as mudanças.
            // O índice 'products_sku_index' já foi criado na migração base, por isso não o repetimos aqui.
            if (Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->change();
            } else {
                $table->string('sku')->nullable();
            }
        });
    }

    public function down()
    {
        // Não removemos colunas em produção para segurança dos dados
    }
};