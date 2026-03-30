<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Tabela de "Pouso" (Landing Zone) para Webhooks.
     * Segue o padrão Queue-First: Salva rápido, processa depois.
     */
    public function up(): void
    {
        Schema::create('webhook_payloads', function (Blueprint $table) {
            $table->id();
            $table->string('platform')->index()->comment('mercadolibre, shopee, etc');
            $table->string('external_resource_id')->nullable()->index();

            $table->json('payload');

            $table->string('status')->default('pending')->index(); // pending, processing, processed, failed
            $table->timestamp('processed_at')->nullable();
            $table->text('error_log')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_payloads');
    }
};
