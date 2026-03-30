<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Tabela Master SKU (O Cérebro)
        if (!Schema::hasTable('master_skus')) {
            Schema::create('master_skus', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('sku')->index();
                $table->string('ean')->nullable()->index();
                $table->string('title');
                $table->decimal('cost_price', 10, 2)->default(0);
                $table->integer('lead_time')->default(15)->comment('Tempo de reposição');
                $table->integer('safety_stock')->default(5)->comment('Estoque segurança');
                $table->timestamps();
            });
        }

        // 2. Tabela War Room (Monitoramento)
        if (!Schema::hasTable('competitor_monitor')) {
            Schema::create('competitor_monitor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('master_sku_id')->constrained('master_skus')->onDelete('cascade');
                $table->string('competitor_mlb');
                $table->decimal('competitor_price', 10, 2);
                $table->decimal('last_price', 10, 2)->nullable();
                $table->timestamp('last_scan_at')->useCurrent();
                $table->timestamps();
            });
        }

        // 3. Tabela Contas ML
        if (!Schema::hasTable('ml_accounts')) {
            Schema::create('ml_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('nickname');
                $table->string('seller_id')->unique();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->timestamps();
            });
        }

        // 4. Atualização de PRODUTOS (Vínculos)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'master_sku_id')) {
                $table->foreignId('master_sku_id')->nullable()->after('id');
            }
            if (!Schema::hasColumn('products', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable()->after('company_id');
            }
            if (!Schema::hasColumn('products', 'stock_quantity')) {
                $table->integer('stock_quantity')->default(0);
            }
            // Garante index no SKU
            if (Schema::hasColumn('products', 'sku')) {
                // Verifica se index existe antes de tentar criar (MySQL específico)
                $indices = collect(DB::select("SHOW INDEXES FROM products"))->pluck('Key_name')->toArray();
                if (!in_array('products_sku_index', $indices)) {
                     $table->index('sku', 'products_sku_index');
                }
            }
        });

        // 5. Atualização de PEDIDOS (Financeiro Real)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) {
                $table->decimal('total_paid_amount', 15, 2)->default(0)->comment('Valor Bruto');
            }
            if (!Schema::hasColumn('orders', 'sale_fee')) {
                $table->decimal('sale_fee', 10, 2)->default(0)->comment('Taxa ML');
            }
            if (!Schema::hasColumn('orders', 'net_shipping_cost')) {
                $table->decimal('net_shipping_cost', 10, 2)->default(0)->comment('Custo Envio Real');
            }
            if (!Schema::hasColumn('orders', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
        });
    }

    public function down() {}
};