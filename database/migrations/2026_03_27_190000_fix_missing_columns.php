<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tabela Orders - Adiciona cost_tax_platform se não existir
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'cost_tax_platform')) {
                    $table->decimal('cost_tax_platform', 10, 2)->default(0)->after('cost_fee_taxes');
                }
            });
        }

        // 2. Tabela Products - Adiciona colunas se não existirem
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (!Schema::hasColumn('products', 'external_id')) {
                    $table->string('external_id')->nullable()->after('id');
                }
                if (!Schema::hasColumn('products', 'image_url')) {
                    $table->string('image_url')->nullable()->after('title');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('cost_tax_platform');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
