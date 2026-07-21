<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração de precificação por empresa: parâmetros globais (imposto, MC,
 * descontos padrão) e a tabela de canais (comissão, faixa, markup, descontos)
 * — antes fixos no código, agora editáveis e persistidos por empresa.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('pricing_settings')) {
            Schema::create('pricing_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
                $table->decimal('imposto', 8, 3)->default(8);
                $table->decimal('mc', 8, 3)->default(11);
                $table->decimal('desc_atual_default', 8, 3)->default(20);
                $table->decimal('desc_equil_default', 8, 3)->default(10);
                $table->decimal('rounding', 8, 2)->default(0.90);
                $table->timestamps();
                $table->unique('company_id');
            });
        }

        if (!Schema::hasTable('channel_settings')) {
            Schema::create('channel_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('channel_key');
                $table->string('label');
                $table->string('origem')->default('pvatual'); // pvatual | centauro | renner
                $table->string('col')->nullable();            // coluna de PV atual correspondente
                $table->decimal('comissao', 8, 3)->default(0);
                $table->string('tem_faixa')->default('none'); // none | ml | shopee
                $table->decimal('markup', 8, 3)->default(23.433);
                $table->decimal('desc_atual', 8, 3)->default(20);
                $table->decimal('desc_equil', 8, 3)->default(10);
                $table->boolean('active')->default(true);
                $table->integer('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['company_id', 'channel_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_settings');
        Schema::dropIfExists('pricing_settings');
    }
};
