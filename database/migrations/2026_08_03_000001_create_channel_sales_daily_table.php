<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (03/08/2026): automatizar as planilhas soltas
 * "DIÁRIO DE VENDAS" (uma aba por canal, uma linha por dia) e "GERAL"
 * (mensal/anual, comparativo ano a ano) que ele mantinha manualmente.
 *
 * Modelagem: só a série diária por canal é persistida aqui — o relatório
 * "Geral" (mensal, semanal, comparativo 2025x2026, conta Matriz/Filial do
 * Mercado Livre) é 100% CALCULADO a partir desta tabela em tempo de leitura
 * (SalesChannelReportService), nunca duplicado. Isso é o próprio desenho
 * que o cliente descreveu ("um alimenta o outro") e evita fabricar/gravar
 * um número que diverge do dado-fonte quando o diário for reimportado.
 *
 * Sem FK estrita em company_id (schema resiliente, CLAUDE.md §4, mesmo
 * padrão de marketing_campaigns/decision_cycles).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_sales_daily')) {
            return;
        }

        Schema::create('channel_sales_daily', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->string('channel', 40); // ver App\Support\SalesChannels
            $table->date('sale_date');

            $table->decimal('gross_value', 14, 2)->default(0);    // PEDIDOS EFETUADOS
            $table->decimal('paid_value', 14, 2)->default(0);     // PEDIDOS PAGOS
            $table->decimal('canceled_value', 14, 2)->default(0); // PEDIDOS CANCELADOS
            $table->decimal('fees', 14, 2)->default(0);           // TARIFAS DE VENDA
            $table->decimal('shipping_cost', 14, 2)->default(0);  // CUSTO COM FRETE
            $table->decimal('net_value', 14, 2)->default(0);      // TOTAL LÍQUIDO (APÓS TAXAS)
            $table->unsignedInteger('orders_count')->default(0);  // NUMERO DE PEDIDOS

            $table->string('source_file')->nullable(); // auditoria: qual upload gravou/atualizou por último
            $table->timestamps();

            $table->unique(['company_id', 'channel', 'sale_date'], 'channel_sales_daily_unique');
            $table->index(['company_id', 'sale_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sales_daily');
    }
};
