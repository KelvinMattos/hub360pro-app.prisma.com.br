<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (01/08/2026): vendas de queima de estoque não devem
 * alimentar giro/prioridade de reposição. `qty_clearance_30` é informativo —
 * quantas unidades venderam abaixo da meta lucro nos últimos 30d, excluídas
 * do cálculo de velocidade — pra a tela poder mostrar isso de forma
 * transparente (nunca esconder o que foi descartado do cálculo).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('replenishment_plan') && !Schema::hasColumn('replenishment_plan', 'qty_clearance_30')) {
            Schema::table('replenishment_plan', function (Blueprint $table) {
                $table->integer('qty_clearance_30')->default(0)->after('revenue_30d');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('replenishment_plan') && Schema::hasColumn('replenishment_plan', 'qty_clearance_30')) {
            Schema::table('replenishment_plan', function (Blueprint $table) {
                $table->dropColumn('qty_clearance_30');
            });
        }
    }
};
