<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendário de datas comerciais — base para o planejamento sazonal do
 * módulo de Marketing. `company_id` nulo = data padrão (seed, visível pra
 * todas as empresas); preenchido = data própria da empresa (manual ou
 * importada via CSV).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('commercial_dates')) {
            return;
        }

        Schema::create('commercial_dates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->date('date');
            $table->string('title', 255);
            $table->string('category', 40)->nullable(); // sazonal|feriado|liquidacao|proprio
            $table->boolean('recurring_yearly')->default(true);
            $table->string('source', 20)->default('manual'); // seed|import|manual
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commercial_dates');
    }
};
