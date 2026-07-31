<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Colunas necessárias pra identificar o cliente pelo CPF independente do
 * canal (Mercado Livre, Shopee, Magazord, Netshoes) — CustomerIdentityService.
 *
 * `customers.doc_number`/`doc_type`/`external_id` já eram referenciadas pelo
 * model Customer e por SyncOrdersCommand, mas nenhuma migration deste repo
 * realmente as cria: a tabela `customers` tem duas definições concorrentes
 * (`2026_01_25_000000_create_hub360_base_tables.php` e
 * `2026_01_26_143823_create_customers_table.php`), e como a primeira roda
 * antes, a segunda nunca executa seu `Schema::create` (a tabela já existe).
 * Mesma lógica pra `orders.customer_doc`/`billing_doc_number`, já usadas por
 * CustomerController e pelos importadores Magazord/Netshoes, mas ausentes
 * de qualquer migration — divergência model/schema do mesmo tipo já
 * documentada no CLAUDE.md §4 (caso `products.brand`).
 *
 * Não mexe em `orders.customer_name`: adicioná-la mudaria a ordem de
 * preferência do `$pick(['customer_name', 'buyer_nickname'])` já usado nos
 * importadores, alterando pra qual coluna o nome do cliente é gravado hoje
 * — fora do escopo deste fix (que é sobre o CPF), então CustomerController
 * resolve o nome de forma defensiva (customer_name -> buyer_nickname).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                $isNew = !Schema::hasColumn('customers', 'doc_number');
                if (!Schema::hasColumn('customers', 'doc_type')) {
                    $table->string('doc_type', 10)->nullable();
                }
                if (!Schema::hasColumn('customers', 'doc_number')) {
                    $table->string('doc_number', 20)->nullable();
                }
                if (!Schema::hasColumn('customers', 'external_id')) {
                    $table->string('external_id')->nullable();
                }
                if ($isNew) {
                    $table->index(['company_id', 'doc_number']);
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'customer_doc')) {
                    $table->string('customer_doc', 20)->nullable();
                }
                if (!Schema::hasColumn('orders', 'billing_doc_number')) {
                    $table->string('billing_doc_number', 20)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('customers')) {
            Schema::table('customers', function (Blueprint $table) {
                foreach (['doc_type', 'doc_number', 'external_id'] as $col) {
                    if (Schema::hasColumn('customers', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                foreach (['customer_doc', 'billing_doc_number'] as $col) {
                    if (Schema::hasColumn('orders', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
