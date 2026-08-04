<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (04/08/2026): as colunas "Origem - Source", "Origem -
 * Medium", "Origem - Referência (site)", "Origem - Campaign" e "Origem -
 * Dispositivo" já existem na "Consulta de Pedidos" do Magazord (mesma fonte
 * do importador "Vendas") e eram descartadas — é o dado de origem real da
 * venda (UTM), pré-requisito para cruzar com gasto de ADS (Google Ads/Meta
 * Ads) e calcular ROAS/CPA por campanha.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'utm_source')) {
                $table->string('utm_source', 190)->nullable()->after('surcharge_amount');
            }
            if (!Schema::hasColumn('orders', 'utm_medium')) {
                $table->string('utm_medium', 190)->nullable()->after('utm_source');
            }
            if (!Schema::hasColumn('orders', 'utm_campaign')) {
                $table->string('utm_campaign', 190)->nullable()->after('utm_medium');
            }
            if (!Schema::hasColumn('orders', 'utm_referrer')) {
                $table->string('utm_referrer', 190)->nullable()->after('utm_campaign');
            }
            if (!Schema::hasColumn('orders', 'utm_device')) {
                $table->string('utm_device', 60)->nullable()->after('utm_referrer');
            }
        });
        if (!$this->hasIndex('orders', 'orders_company_utm_source_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['company_id', 'utm_source'], 'orders_company_utm_source_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        if ($this->hasIndex('orders', 'orders_company_utm_source_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropIndex('orders_company_utm_source_index');
            });
        }
        Schema::table('orders', function (Blueprint $table) {
            foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_referrer', 'utm_device'] as $col) {
                if (Schema::hasColumn('orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    private function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->pluck('name')->contains($index);
    }
};
