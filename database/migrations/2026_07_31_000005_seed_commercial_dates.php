<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Datas comerciais padrão (company_id nulo — visíveis pra toda empresa).
 *
 * Só entram aqui datas que eu tenho como confirmar: fixas de calendário
 * (recurring_yearly=true, corretas todo ano) ou móveis com a data EXATA já
 * calculada pra 2026 (recurring_yearly=false, com nota — precisam ser
 * reconferidas/recadastradas ano a ano, nunca "chutadas" pra frente).
 * Carnaval e Páscoa ficam de fora: dependem do cálculo do Domingo de Páscoa
 * (computus), que não vale a pena aproximar aqui — cadastro manual ou
 * importação cobre esses casos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('commercial_dates')) {
            return;
        }

        if (DB::table('commercial_dates')->where('source', 'seed')->exists()) {
            return;
        }

        $now = now();
        $fixed = [
            ['date' => '2026-01-01', 'title' => 'Confraternização Universal (Ano Novo)', 'category' => 'feriado'],
            ['date' => '2026-03-15', 'title' => 'Dia do Consumidor', 'category' => 'sazonal'],
            ['date' => '2026-06-12', 'title' => 'Dia dos Namorados', 'category' => 'sazonal'],
            ['date' => '2026-09-15', 'title' => 'Dia do Cliente', 'category' => 'sazonal'],
            ['date' => '2026-10-12', 'title' => 'Dia das Crianças', 'category' => 'sazonal'],
            ['date' => '2026-12-25', 'title' => 'Natal', 'category' => 'feriado'],
            ['date' => '2026-12-31', 'title' => 'Véspera de Ano Novo (balanço de estoque)', 'category' => 'feriado'],
        ];

        $movable2026 = [
            ['date' => '2026-05-10', 'title' => 'Dia das Mães', 'category' => 'sazonal', 'notes' => '2º domingo de maio — data muda todo ano, atualizar em 2027.'],
            ['date' => '2026-08-09', 'title' => 'Dia dos Pais', 'category' => 'sazonal', 'notes' => '2º domingo de agosto — data muda todo ano, atualizar em 2027.'],
            ['date' => '2026-11-27', 'title' => 'Black Friday', 'category' => 'sazonal', 'notes' => '4ª sexta-feira de novembro — data muda todo ano, atualizar em 2027.'],
        ];

        $rows = [];
        foreach ($fixed as $d) {
            $rows[] = [
                'company_id' => null, 'date' => $d['date'], 'title' => $d['title'], 'category' => $d['category'],
                'recurring_yearly' => true, 'source' => 'seed', 'notes' => null,
                'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach ($movable2026 as $d) {
            $rows[] = [
                'company_id' => null, 'date' => $d['date'], 'title' => $d['title'], 'category' => $d['category'],
                'recurring_yearly' => false, 'source' => 'seed', 'notes' => $d['notes'],
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        DB::table('commercial_dates')->insert($rows);
    }

    public function down(): void
    {
        if (Schema::hasTable('commercial_dates')) {
            DB::table('commercial_dates')->where('source', 'seed')->delete();
        }
    }
};
