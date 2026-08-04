<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (04/08/2026): integração de verdade via API do Google
 * Ads (não upload de planilha) — "aprenda e integre". Reaproveita a mesma
 * tabela `integrations`/mesmo fluxo OAuth2 já usado com o Mercado Livre
 * (SettingsController::redirectToMeli/handleMeliCallback,
 * MeliRefreshTokenCommand): uma linha por conta conectada (platform =
 * 'google_ads', seller_id = Customer ID do Google Ads), reutilizando
 * app_id/client_secret/access_token/refresh_token/token_expires_at que já
 * existem na tabela.
 *
 * Colunas novas específicas do Google Ads:
 *  - developer_token: exigido em toda chamada à API (o cliente já tem).
 *  - login_customer_id: Customer ID da conta gerenciadora (MCC), quando a
 *    conta que autorizou não é a mesma que tem o acesso direto.
 *  - last_sync_at/last_sync_status/last_sync_error: o fluxo do Mercado
 *    Livre não tinha onde persistir falha de sincronização (só log) — aqui
 *    fica visível na tela de Conexões, corrigindo essa lacuna (CLAUDE.md §2.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('integrations')) {
            return;
        }
        Schema::table('integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('integrations', 'developer_token')) {
                $table->string('developer_token', 190)->nullable()->after('client_secret');
            }
            if (!Schema::hasColumn('integrations', 'login_customer_id')) {
                $table->string('login_customer_id', 20)->nullable()->after('developer_token');
            }
            if (!Schema::hasColumn('integrations', 'last_sync_at')) {
                $table->timestamp('last_sync_at')->nullable();
            }
            if (!Schema::hasColumn('integrations', 'last_sync_status')) {
                $table->string('last_sync_status', 20)->nullable();
            }
            if (!Schema::hasColumn('integrations', 'last_sync_error')) {
                $table->text('last_sync_error')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('integrations')) {
            return;
        }
        Schema::table('integrations', function (Blueprint $table) {
            foreach (['developer_token', 'login_customer_id', 'last_sync_at', 'last_sync_status', 'last_sync_error'] as $col) {
                if (Schema::hasColumn('integrations', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
