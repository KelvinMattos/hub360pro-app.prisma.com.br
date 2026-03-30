<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('orders', function (Blueprint $table) {
            // Campos para Data Mining (Armazenam a resposta completa da API)
            if (!Schema::hasColumn('orders', 'json_order')) $table->json('json_order')->nullable();
            if (!Schema::hasColumn('orders', 'json_shipment')) $table->json('json_shipment')->nullable();
            if (!Schema::hasColumn('orders', 'json_payments')) $table->json('json_payments')->nullable();

            // Dados Ricos de Logística e Cliente
            if (!Schema::hasColumn('orders', 'logistic_type')) $table->string('logistic_type')->nullable(); // cross_docking, fulfillment, self_service
            if (!Schema::hasColumn('orders', 'shipping_mode')) $table->string('shipping_mode')->nullable(); // me2, me1
            if (!Schema::hasColumn('orders', 'buyer_nickname')) $table->string('buyer_nickname')->nullable();
            
            // Garantia dos campos financeiros (caso não tenha rodado a anterior)
            if (!Schema::hasColumn('orders', 'cost_operational')) $table->decimal('cost_operational', 10, 2)->default(0);
            if (!Schema::hasColumn('orders', 'contribution_margin')) $table->decimal('contribution_margin', 10, 2)->default(0);
        });
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'json_order', 'json_shipment', 'json_payments', 
                'logistic_type', 'shipping_mode', 'buyer_nickname'
            ]);
        });
    }
};