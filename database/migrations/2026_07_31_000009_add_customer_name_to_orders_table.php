<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Incidente: a coluna "Cliente" em Pedidos Recentes (/sales) ficava em
 * branco pra pedidos Magazord/Netshoes. Causa: esses importadores gravam o
 * nome em `customer_name` (prioridade do `$pick(['customer_name',
 * 'buyer_nickname'])`), mas SalesController::recentes() só lia
 * `buyer_nickname` (legado, exclusivo Mercado Livre) — e essa coluna nunca
 * era criada por nenhuma migration deste repo (mesma divergência model/
 * schema do CLAUDE.md §4, caso `products.brand`).
 *
 * Adicionar essa coluna aqui também alinha o ambiente de desenvolvimento
 * com a produção: `$pick()` passa a preferir `customer_name` (mais
 * correto — funciona pra qualquer canal, não só Mercado Livre) em vez de
 * cair pro `buyer_nickname`, igual já deve acontecer em produção hoje.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('orders')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            if (!Schema::hasColumn('orders', 'customer_name')) {
                $table->string('customer_name')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('orders') || !Schema::hasColumn('orders', 'customer_name')) {
            return;
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('customer_name');
        });
    }
};
