<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (03/08/2026): os importadores nativos de Vendas por
 * canal (Mercado Livre, Shopee, Centauro, Renner, Magazine Luiza) precisam
 * suportar MAIS DE UMA CONTA por canal (ex.: duas contas de Mercado Livre,
 * três da Shopee).
 *
 * Deliberadamente separado de `marketplace_credentials`/`integrations`
 * (que guardam token OAuth da API oficial do Mercado Livre) — aqui é só um
 * registro leve pra rotular de qual conta física veio cada pedido
 * importado por arquivo, sem token nem credencial nenhuma.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_channel_accounts')) {
            return;
        }

        Schema::create('sales_channel_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->string('channel', 40); // mercado_livre|shopee|centauro|renner|magalu
            $table->string('label', 120);
            $table->string('external_identifier', 120)->nullable(); // ex.: "Account" da Centauro, seller_id da ML
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['company_id', 'channel', 'label'], 'sales_channel_accounts_unique');
        });

        if (Schema::hasTable('orders') && !Schema::hasColumn('orders', 'sales_channel_account_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('sales_channel_account_id')->nullable()->index()->after('selling_channel');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'sales_channel_account_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('sales_channel_account_id');
            });
        }
        Schema::dropIfExists('sales_channel_accounts');
    }
};
