<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        // 1. Configurações Globais (Empresa)
        if (!Schema::hasTable('companies'))
            return;
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->default(6.00); // Ex: 6% Simples
            }
            if (!Schema::hasColumn('companies', 'operational_cost_rate')) {
                $table->decimal('operational_cost_rate', 5, 2)->default(10.00); // Ex: 10% Estrutura
            }
        });

        // 2. Snapshot Financeiro (Pedido)
        if (!Schema::hasTable('orders'))
            return;
        Schema::table('orders', function (Blueprint $table) {
            // Limpeza de campos antigos (se existirem)
            if (Schema::hasColumn('orders', 'custom_tax_rate')) {
                $table->dropColumn(['custom_tax_rate', 'extra_costs']);
            }

            // Colunas de Resultado da DRE
            if (!Schema::hasColumn('orders', 'cost_products')) {
                $table->decimal('cost_products', 15, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('orders', 'cost_operational')) {
                $table->decimal('cost_operational', 10, 2)->default(0)->after('cost_products');
            }
            if (!Schema::hasColumn('orders', 'contribution_margin')) {
                $table->decimal('contribution_margin', 10, 2)->default(0)->after('cost_operational');
            }
        });
    }

    public function down()
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'operational_cost_rate']);
        });
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cost_operational', 'contribution_margin']);
        });
    }
};