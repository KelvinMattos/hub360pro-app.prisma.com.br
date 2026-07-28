<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuração do monitoramento por empresa (nome da nossa loja nos canais,
 * parâmetros do scraper: template de URL, delay, limite por rodada, etc).
 * Guardado como JSON para evoluir sem migration nova.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('monitoring_settings')) {
            return;
        }

        Schema::create('monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->unique();
            $table->json('config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};
