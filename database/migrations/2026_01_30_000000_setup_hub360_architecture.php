<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Tabela de Contas ML
        if (!Schema::hasTable('ml_accounts')) {
            Schema::create('ml_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('nickname');
                $table->string('seller_id')->unique();
                $table->string('access_token')->nullable();
                $table->string('refresh_token')->nullable();
                $table->timestamps();
            });
        }

        // 2. Tabela Master SKU
        if (!Schema::hasTable('master_skus')) {
            Schema::create('master_skus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('sku')->index();
                $table->string('ean')->nullable()->index();
                $table->string('title');
                $table->decimal('cost_price', 10, 2)->default(0);
                $table->integer('lead_time')->default(15);
                $table->integer('stock_buffer')->default(5);
                $table->timestamps();
                $table->unique(['company_id', 'sku']);
            });
        }

        // 3. Tabela War Room
        if (!Schema::hasTable('competitor_monitor')) {
            Schema::create('competitor_monitor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('master_sku_id')->constrained('master_skus')->onDelete('cascade');
                $table->string('competitor_mlb')->index();
                $table->decimal('competitor_price', 10, 2);
                $table->string('competitor_seller_name')->nullable();
                $table->timestamp('last_scan_at')->useCurrent();
                $table->timestamps();
            });
        }

        // 4. Atualização Orders (Colunas Financeiras)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) $table->decimal('total_paid_amount', 15, 2)->default(0);
            if (!Schema::hasColumn('orders', 'ml_fee_amount')) $table->decimal('ml_fee_amount', 10, 2)->default(0);
            if (!Schema::hasColumn('orders', 'net_shipping_cost')) $table->decimal('net_shipping_cost', 10, 2)->default(0);
            if (!Schema::hasColumn('orders', 'ml_account_id')) $table->foreignId('ml_account_id')->nullable();
        });

        // 5. Atualização Products e Índices (Correção do Erro 1061)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
            if (!Schema::hasColumn('products', 'lead_time')) {
                $table->integer('lead_time')->default(15);
            }
            
            // Garante que a coluna SKU seja nullable, mas NÃO cria o index aqui para evitar erro
            if (Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->change();
            }
        });

        // CRIAÇÃO SEGURA DE ÍNDICE
        // Verifica se o índice já existe antes de tentar criar
        $indexes = collect(DB::select("SHOW INDEXES FROM products"))->pluck('Key_name')->toArray();
        
        if (!in_array('products_sku_index', $indexes)) {
            Schema::table('products', function (Blueprint $table) {
                $table->index('sku', 'products_sku_index');
            });
        }
        
        if (!in_array('products_ean_index', $indexes) && Schema::hasColumn('products', 'ean')) {
             Schema::table('products', function (Blueprint $table) {
                $table->index('ean', 'products_ean_index');
            });
        }
    }

    public function down() {}
};