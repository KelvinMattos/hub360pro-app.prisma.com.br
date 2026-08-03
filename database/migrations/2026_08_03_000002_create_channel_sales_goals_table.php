<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Meta mensal de faturamento por canal (ou geral, `channel = ''`), pra
 * calcular "% realizado da meta" honestamente a partir de um valor que o
 * próprio usuário define — a planilha "GERAL" original tinha uma coluna de
 * meta com uma fórmula de projeção (~15%) que não foi possível confirmar
 * com o cliente (CLAUDE.md §2.4: não construir em cima de suposição), então
 * em vez de tentar reproduzir a fórmula antiga, a meta agora é um dado real
 * que o usuário escreve — nunca um número inventado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channel_sales_goals')) {
            return;
        }

        Schema::create('channel_sales_goals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->string('channel', 40)->default(''); // '' = meta geral (todos os canais)
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('goal_amount', 14, 2);

            $table->timestamps();

            $table->unique(['company_id', 'channel', 'year', 'month'], 'channel_sales_goals_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sales_goals');
    }
};
