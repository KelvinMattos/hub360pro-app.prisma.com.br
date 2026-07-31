<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compras > Notas Fiscais de Compra: indexação de texto dos PDFs armazenados
 * no disco `notas_fiscais`, com busca full-text por produto (nome/EAN/código).
 *
 * `supplier_id` não usa ->constrained(): o schema de produção diverge do
 * repo em outras tabelas (ver CLAUDE.md §4) — evita que a migration inteira
 * falhe caso `suppliers` não exista do jeito esperado. `fornecedor` (texto)
 * é o fallback quando não há vínculo com a tabela de fornecedores.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notas_fiscais_compra')) {
            Schema::create('notas_fiscais_compra', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->unsignedBigInteger('supplier_id')->nullable();
                $table->string('fornecedor')->nullable();
                $table->string('path');
                $table->string('filename');
                $table->date('data_emissao')->nullable();
                $table->string('hash', 64)->nullable();
                $table->unsignedInteger('pages_count')->default(0);
                $table->string('status')->default('pending'); // pending, indexed, failed, orphaned
                $table->text('error')->nullable();
                $table->timestamp('indexed_at')->nullable();
                $table->timestamps();

                $table->index('company_id');
                $table->index('status');
                $table->unique(['company_id', 'path']);
            });
        }

        if (! Schema::hasTable('nota_fiscal_paginas')) {
            Schema::create('nota_fiscal_paginas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('nota_fiscal_compra_id');
                $table->unsignedInteger('page_number');
                $table->longText('content')->nullable();
                $table->timestamps();

                $table->index('nota_fiscal_compra_id');
                $table->fullText('content');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_fiscal_paginas');
        Schema::dropIfExists('notas_fiscais_compra');
    }
};
