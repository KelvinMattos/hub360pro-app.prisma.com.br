<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // Cria a tabela customers apenas se ela não existir
        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                
                // Identificação
                $table->string('name');
                $table->string('doc_type')->nullable(); // CPF, CNPJ
                $table->string('doc_number')->nullable(); // O CPF em si
                $table->string('external_id')->nullable(); // ID do usuário no ML
                
                // Contato
                $table->string('email')->nullable();
                $table->string('phone')->nullable();
                
                // Endereço Principal
                $table->string('city')->nullable();
                $table->string('state')->nullable();
                
                // Métricas de CRM
                $table->integer('orders_count')->default(0);
                $table->decimal('total_spent', 12, 2)->default(0);
                $table->timestamp('last_purchase_date')->nullable();
                
                $table->timestamps();

                $table->index(['company_id', 'doc_number']);
                $table->index(['company_id', 'external_id']);
            });
        }

        // Adiciona a chave estrangeira na tabela de pedidos se necessário
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'customer_id')) {
                $table->unsignedBigInteger('customer_id')->nullable()->after('company_id');
            }
        });
    }

    public function down()
    {
        Schema::dropIfExists('customers');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_id');
        });
    }
};