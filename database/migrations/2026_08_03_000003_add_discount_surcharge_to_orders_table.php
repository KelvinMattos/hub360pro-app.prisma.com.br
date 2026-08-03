<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pedido do cliente (03/08/2026): revisar se os importadores de Vendas do
 * Magazord (Consulta Dinâmica "Detalhes do Pedido" e o modelo "Vendas" rico
 * de Pedidos) aproveitam tudo que a planilha real traz.
 *
 * Achado real (validado contra os arquivos de origem, ver
 * IMPORTAR_VENDAS.csv e a Consulta Dinâmica de Detalhes): as colunas "Valor
 * Desconto"/"Vlr Desconto" e "Valor Acréscimo"/"Vlr Acréscimo" existem nos
 * dois modelos e eram descartadas silenciosamente — `orders` não tinha onde
 * gravá-las. Acréscimo (juros de parcelamento cobrado do cliente) e desconto
 * afetam o valor real do pedido; sem eles, Valor Total capturado already
 * inclui os efeitos líquidos, mas perde-se a composição.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'discount_amount')) {
                $table->decimal('discount_amount', 12, 2)->nullable()->after('shipping_cost');
            }
            if (!Schema::hasColumn('orders', 'surcharge_amount')) {
                $table->decimal('surcharge_amount', 12, 2)->nullable()->after('discount_amount');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }
        Schema::table('orders', function (Blueprint $table) {
            if (Schema::hasColumn('orders', 'surcharge_amount')) {
                $table->dropColumn('surcharge_amount');
            }
            if (Schema::hasColumn('orders', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }
        });
    }
};
