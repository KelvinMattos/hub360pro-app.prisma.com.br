<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration 
{
    /**
     * Tabela de Log de Webhooks (HUB 360 PRO).
     * Essencial para rastreabilidade, idempotência e depuração de integrações.
     */
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->onDelete('cascade');

            $table->string('source')->index(); // mercadolibre, shopee, amazon
            $table->string('event_type')->nullable()->index(); // order.created, order.updated, etc
            $table->json('payload');

            $table->enum('status', ['pending', 'processing', 'processed', 'failed'])->default('pending')->index();
            $table->text('error_message')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
