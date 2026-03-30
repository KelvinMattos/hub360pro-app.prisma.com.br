<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        // 1. Tabela Contas ML (Conexão)
        if (!Schema::hasTable('ml_accounts')) {
            Schema::create('ml_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('nickname');
                $table->string('seller_id')->unique();
                $table->text('access_token')->nullable();
                $table->text('refresh_token')->nullable();
                $table->dateTime('expires_at')->nullable();
                $table->timestamps();
            });
        }

        // 2. Tabela Master Products (O Cérebro)
        if (!Schema::hasTable('master_products')) {
            Schema::create('master_products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->onDelete('cascade');
                $table->string('sku')->index(); // DNA Único
                $table->string('ean')->nullable()->index();
                $table->string('title');
                $table->decimal('cost_price', 10, 2)->default(0);
                $table->integer('lead_time')->default(15)->comment('Prazo Reposição');
                $table->integer('safety_stock')->default(5)->comment('Estoque Segurança');
                $table->timestamps();
            });
        }

        // 3. Atualização de Produtos (Sensores/Anúncios)
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'master_product_id')) {
                $table->foreignId('master_product_id')->nullable()->constrained('master_products')->onDelete('set null');
            }
            if (!Schema::hasColumn('products', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
            if (!Schema::hasColumn('products', 'stock_quantity')) {
                $table->integer('stock_quantity')->default(0);
            }
        });

        // 4. Atualização de Pedidos (Financeiro Real)
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'total_paid_amount')) {
                $table->decimal('total_paid_amount', 15, 2)->default(0)->comment('Valor Bruto Pago');
            }
            if (!Schema::hasColumn('orders', 'ml_fee_amount')) {
                $table->decimal('ml_fee_amount', 10, 2)->default(0)->comment('Comissão ML');
            }
            if (!Schema::hasColumn('orders', 'shipping_cost_net')) {
                $table->decimal('shipping_cost_net', 10, 2)->default(0)->comment('Custo Frete Real');
            }
            if (!Schema::hasColumn('orders', 'ml_account_id')) {
                $table->foreignId('ml_account_id')->nullable();
            }
        });

        // 5. Tabela War Room (Concorrência)
        if (!Schema::hasTable('competitor_monitor')) {
            Schema::create('competitor_monitor', function (Blueprint $table) {
                $table->id();
                $table->foreignId('master_product_id')->constrained('master_products')->onDelete('cascade');
                $table->string('competitor_mlb');
                $table->decimal('competitor_price', 10, 2);
                $table->timestamp('last_scan')->useCurrent();
                $table->timestamps();
            });
        }
    }

    public function down() {}
};