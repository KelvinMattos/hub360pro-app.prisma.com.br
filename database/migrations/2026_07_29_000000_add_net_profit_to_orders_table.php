<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * O model Order já tinha `net_profit` em $fillable/$casts, mas nenhuma
 * migration criava a coluna — ReportController::index() fazia
 * SUM(net_profit) sobre coluna inexistente (erro SQL "Unknown column").
 *
 * Valor é calculado por App\Services\Financial\NetProfitCalculator, nunca
 * escrito diretamente pelos controllers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'net_profit')) {
                $table->decimal('net_profit', 12, 2)->nullable()->after('contribution_margin');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'net_profit')) {
                $table->dropColumn('net_profit');
            }
        });
    }
};
