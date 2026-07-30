<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ReplenishmentEngine e SkuStrategyClassifier agregam vendas com
 * `orders.company_id = ? AND orders.date_created >= ?` (join a partir de
 * order_items) — sem índice, isso vira scan completo da tabela de pedidos a
 * cada cálculo. `company_id` já tinha índice próprio, `date_created` não
 * tinha nenhum. Composto (company_id, date_created) cobre exatamente o
 * padrão de filtro das duas classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'date_created')) {
            return;
        }

        $indexExists = collect(DB::select('SHOW INDEX FROM orders'))
            ->pluck('Key_name')
            ->contains('orders_company_id_date_created_index');

        if ($indexExists) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->index(['company_id', 'date_created'], 'orders_company_id_date_created_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        $indexExists = collect(DB::select('SHOW INDEX FROM orders'))
            ->pluck('Key_name')
            ->contains('orders_company_id_date_created_index');

        if ($indexExists) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_company_id_date_created_index');
            });
        }
    }
};
