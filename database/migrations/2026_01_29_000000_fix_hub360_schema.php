<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // 1. Correção da Tabela ORDERS (Financeiro)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) {
                $table->decimal('total_paid_amount', 15, 2)->default(0)->index();
            }
            if (!Schema::hasColumn('orders', 'ml_fee_amount')) {
                $table->decimal('ml_fee_amount', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'net_shipping_cost')) {
                $table->decimal('net_shipping_cost', 10, 2)->default(0);
            }
            if (!Schema::hasColumn('orders', 'ml_account_id')) {
                $table->string('ml_account_id')->nullable()->index();
            }
        });

        // 2. Correção da Tabela PRODUCTS (Master SKU)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'lead_time')) {
                $table->integer('lead_time')->default(15);
            }
            if (!Schema::hasColumn('products', 'safety_stock')) {
                $table->integer('safety_stock')->default(5);
            }
            // Verifica e adiciona EAN se não existir
            if (!Schema::hasColumn('products', 'ean')) {
                $table->string('ean')->nullable()->index();
            }
        });
    }

    public function down() {}
};