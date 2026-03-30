<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Refinamento do Schema para ERP e DRE Inteligente.
     * Consolida o controle de despesas fixas e a rastreabilidade de pedidos.
     */
    public function up(): void
    {
        // 1. Tabela de Despesas Fixas (Evolução da operational_expenses para padronização ERP)
        if (!Schema::hasTable('fixed_expenses')) {
            Schema::create('fixed_expenses', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();

                $table->string('description');
                $table->string('category')->nullable(); // Operacional, Marketing, RH, etc.
                $table->decimal('amount', 15, 2);
                $table->date('expense_date'); // Competência

                $table->timestamps();
            });
        }

        // 2. Refinamento da Tabela de Pedidos
        Schema::table('orders', function (Blueprint $table) {
            // Garante âncoras básicas por segurança
            if (!Schema::hasColumn('orders', 'selling_channel')) {
                $table->string('selling_channel')->nullable()->after('company_id');
            }
            if (!Schema::hasColumn('orders', 'date_created')) {
                $table->timestamp('date_created')->nullable()->after('order_date');
            }

            // Marketplace Source e Referência Externa já existem em alguns contextos, mas garantimos aqui
            if (!Schema::hasColumn('orders', 'marketplace_source')) {
                $table->string('marketplace_source')->nullable()->after('selling_channel');
            }

            // Re-aplicando status enum rigoroso
            // Nota: SQLite não suporta alteração de enum facilmente, mas para MySQL/Postgres funciona.
            // Aqui vamos apenas garantir que as colunas de valor e data existam.

            if (!Schema::hasColumn('orders', 'shipping_cost')) {
                $table->decimal('shipping_cost', 15, 2)->default(0)->after('total_amount');
            }
            if (!Schema::hasColumn('orders', 'marketplace_fee')) {
                $table->decimal('marketplace_fee', 15, 2)->default(0)->after('shipping_cost');
            }
            if (!Schema::hasColumn('orders', 'taxes_amount')) {
                $table->decimal('taxes_amount', 15, 2)->default(0)->after('marketplace_fee');
            }

            // Datas de Competência Cruciais
            if (!Schema::hasColumn('orders', 'captured_at')) {
                $table->timestamp('captured_at')->nullable()->after('date_created');
            }
            if (!Schema::hasColumn('orders', 'invoiced_at')) {
                $table->timestamp('invoiced_at')->nullable()->after('captured_at');
            }
        });

        // 3. Refinamento da Tabela de Itens do Pedido (CMV)
        Schema::table('order_items', function (Blueprint $table) {
            if (!Schema::hasColumn('order_items', 'unit_cost')) {
                $table->decimal('unit_cost', 15, 2)->default(0)->after('unit_price')->comment('CMV no momento da venda');
            }
        });
    }

    public function down(): void
    {
        // O down não deve ser destrutivo para tabelas legadas em produção, 
        // mas aqui mantemos para manter o padrão.
        Schema::dropIfExists('fixed_expenses');
    }
};
