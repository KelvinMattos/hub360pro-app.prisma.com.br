<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Adiciona Configurações Globais na tabela de Empresas
        Schema::table('companies', function (Blueprint $table) {
            if (!Schema::hasColumn('companies', 'tax_rate')) {
                $table->decimal('tax_rate', 5, 2)->default(6.00)->after('document'); // Ex: 6.00%
            }
            if (!Schema::hasColumn('companies', 'operational_cost_rate')) {
                $table->decimal('operational_cost_rate', 5, 2)->default(10.00)->after('tax_rate'); // Ex: 10.00%
            }
        });

        // 2. Adiciona Colunas de Resultado Financeiro na tabela de Pedidos
        Schema::table('orders', function (Blueprint $table) {
            // Garante coluna base
            if (!Schema::hasColumn('orders', 'cost_products')) {
                $table->decimal('cost_products', 15, 2)->default(0)->after('total_amount');
            }

            // Custo Operacional (Valor Monetário calculado no momento do pedido)
            if (!Schema::hasColumn('orders', 'cost_operational')) {
                $table->decimal('cost_operational', 10, 2)->default(0)->after('cost_products');
            }
            
            // Margem de Contribuição (Valor Monetário)
            if (!Schema::hasColumn('orders', 'contribution_margin')) {
                $table->decimal('contribution_margin', 10, 2)->default(0)->after('cost_operational');
            }

            // Limpeza: Remove campos antigos de edição manual, se existirem, pois agora usamos regra global
            if (Schema::hasColumn('orders', 'custom_tax_rate')) {
                $table->dropColumn(['custom_tax_rate', 'extra_costs']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['tax_rate', 'operational_cost_rate']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['cost_operational', 'contribution_margin']);
        });
    }
};