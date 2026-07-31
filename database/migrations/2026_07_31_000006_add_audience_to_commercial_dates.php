<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Público-alvo da data comercial — usado só pra filtrar a campanha-kit
 * sugerida a partir do calendário (CampaignController::createFromDate).
 * Null = sem filtro (datas neutras como Black Friday/Natal continuam
 * exatamente como antes).
 *
 * Incidente: o kit sugerido pro Dia dos Pais recomendou produto "Feminino"
 * — o motor de oportunidades prioriza só venda/estoque parado, sem nenhuma
 * noção de público. Cobre aqui só as duas datas explicitamente com gênero
 * (Mães/Pais); Dia das Crianças fica sem filtro por ora — exigiria uma
 * regra de inclusão ("tem 'infantil' no título"), não de exclusão, e é um
 * tipo de erro diferente (falso negativo, esvaziando a lista) que não foi
 * validado ainda.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commercial_dates')) {
            return;
        }

        Schema::table('commercial_dates', function (Blueprint $table) {
            if (!Schema::hasColumn('commercial_dates', 'audience')) {
                $table->string('audience', 20)->nullable(); // masculino | feminino | null
            }
        });

        DB::table('commercial_dates')->where('source', 'seed')->where('title', 'Dia dos Pais')->update(['audience' => 'masculino']);
        DB::table('commercial_dates')->where('source', 'seed')->where('title', 'Dia das Mães')->update(['audience' => 'feminino']);
    }

    public function down(): void
    {
        if (!Schema::hasTable('commercial_dates') || !Schema::hasColumn('commercial_dates', 'audience')) {
            return;
        }

        Schema::table('commercial_dates', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
