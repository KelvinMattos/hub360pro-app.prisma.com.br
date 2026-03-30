<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. MÓDULO DE COMPRAS & FORNECEDORES
        Schema::create('suppliers', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->string('email')->nullable();
            $blueprint->string('phone')->nullable();
            $blueprint->string('doc_type')->nullable(); // CPF/CNPJ
            $blueprint->string('doc_number')->nullable();
            $blueprint->string('city')->nullable();
            $blueprint->string('state', 2)->nullable();
            $blueprint->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->foreignId('supplier_id')->constrained();
            $blueprint->string('status')->default('pending'); // pending, ordered, received, cancelled
            $blueprint->decimal('total_amount', 15, 2)->default(0);
            $blueprint->date('issue_date');
            $blueprint->date('expected_delivery_date')->nullable();
            $blueprint->text('notes')->nullable();
            $blueprint->timestamps();
        });

        // 2. MÓDULO DE ESTOQUE AVANÇADO
        Schema::create('warehouses', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->string('name');
            $blueprint->boolean('is_default')->default(false);
            $blueprint->string('address')->nullable();
            $blueprint->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->foreignId('product_id')->constrained();
            $blueprint->foreignId('warehouse_id')->constrained();
            $blueprint->integer('quantity');
            $blueprint->string('type'); // in (entrada), out (saída), adjustment (ajuste)
            $blueprint->string('reason')->nullable(); // venda, compra, devolução, quebra
            $blueprint->morphs('reference'); // Link para Order ou PurchaseOrder
            $blueprint->timestamps();
        });

        // 3. MÓDULO FINANCEIRO & BANCÁRIO
        Schema::create('bank_accounts', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->string('name'); // Conta Principal, Caixa Interno, Asaas
            $blueprint->string('bank_name')->nullable();
            $blueprint->string('account_number')->nullable();
            $blueprint->decimal('balance', 15, 2)->default(0);
            $blueprint->string('type')->default('checking'); // checking, savings, cash
            $blueprint->timestamps();
        });

        Schema::create('transactions', function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
            $blueprint->foreignId('bank_account_id')->constrained();
            $blueprint->string('type'); // revenue (receita), expense (despesa), transfer
            $blueprint->decimal('amount', 15, 2);
            $blueprint->string('description');
            $blueprint->date('due_date');
            $blueprint->date('payment_date')->nullable();
            $blueprint->string('status')->default('pending'); // pending, paid, cancelled
            $blueprint->morphs('origin'); // Link para Order (venda) ou PurchaseOrder (compra)
            $blueprint->string('payment_method')->nullable(); // boleto, pix, card
            $blueprint->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('suppliers');
    }
};