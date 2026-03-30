<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                // Custos detalhados do Marketplace
                if (!Schema::hasColumn('orders', 'cost_fee_commission'))
                    $table->decimal('cost_fee_commission', 10, 2)->default(0); // % Venda
                if (!Schema::hasColumn('orders', 'cost_fee_fixed'))
                    $table->decimal('cost_fee_fixed', 10, 2)->default(0); // Taxa fixa
                if (!Schema::hasColumn('orders', 'cost_fee_shipping'))
                    $table->decimal('cost_fee_shipping', 10, 2)->default(0); // Frete pago pelo vendedor
                if (!Schema::hasColumn('orders', 'cost_fee_ads'))
                    $table->decimal('cost_fee_ads', 10, 2)->default(0); // Product Ads
                if (!Schema::hasColumn('orders', 'cost_fee_taxes'))
                    $table->decimal('cost_fee_taxes', 10, 2)->default(0); // Impostos retidos (IIBB, etc)
            });
        }
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cost_fee_commission', 'cost_fee_fixed', 'cost_fee_shipping', 'cost_fee_ads', 'cost_fee_taxes']);
        });
    }
};