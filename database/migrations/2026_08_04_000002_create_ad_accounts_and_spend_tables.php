<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (04/08/2026): monitoramento de ADS (Google Ads / Meta
 * Ads) cruzando gasto de campanha com a receita real das vendas (via UTM,
 * ver 2026_08_04_000001). Suporta múltiplas contas por plataforma, mesmo
 * padrão de `sales_channel_accounts` — cadastro leve, sem credencial.
 *
 * A importação hoje é manual (upload do relatório de campanha exportado do
 * Google Ads / Meta Ads Manager); ver §2.6/§2.7 do CLAUDE.md — não coleta
 * nada por conta própria, e nenhuma credencial de API é gravada aqui. A
 * integração OAuth direta com as APIs oficiais fica para quando o cliente
 * fornecer as credenciais do próprio app (Google Ads dev token, Meta App
 * ID/secret) — ver tela Conexões.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('ad_accounts')) {
            Schema::create('ad_accounts', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();

                $table->string('platform', 40); // google_ads|meta_ads
                $table->string('label', 120);
                $table->string('external_account_id', 120)->nullable(); // Customer ID (Google) / Ad Account ID (Meta)
                $table->boolean('is_active')->default(true);

                $table->timestamps();

                $table->unique(['company_id', 'platform', 'label'], 'ad_accounts_unique');
            });
        }

        if (!Schema::hasTable('ad_spend_daily')) {
            Schema::create('ad_spend_daily', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('ad_account_id')->nullable()->index();

                $table->string('platform', 40);
                $table->date('date');
                $table->string('campaign_name', 190);
                $table->string('campaign_id', 120)->nullable();

                $table->decimal('spend', 12, 2)->default(0);
                $table->unsignedInteger('impressions')->nullable();
                $table->unsignedInteger('clicks')->nullable();
                $table->unsignedInteger('conversions')->nullable();

                $table->timestamps();

                $table->unique(['ad_account_id', 'date', 'campaign_name'], 'ad_spend_daily_unique');
                $table->index(['company_id', 'platform', 'date'], 'ad_spend_daily_company_platform_date');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_daily');
        Schema::dropIfExists('ad_accounts');
    }
};
