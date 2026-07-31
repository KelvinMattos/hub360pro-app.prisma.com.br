<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Marketing: campanha é o container do Kanban (lançamento,
 * liquidação, sazonal, recorrente ou livre), com produtos vinculados
 * (marketing_campaign_products) e tarefas (marketing_tasks).
 *
 * `stage` é a coluna do Kanban — ideia -> planejamento -> execução ->
 * revisão -> concluído. Sem FK estrita em company_id/owner_id/created_by,
 * mesmo padrão de decision_cycles (CLAUDE.md §4 — schema resiliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaigns')) {
            return;
        }

        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();

            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('type', 20)->default('outro'); // lancamento|liquidacao|sazonal|recorrente|outro
            $table->string('stage', 20)->default('ideia'); // ideia|planejamento|execucao|revisao|concluido
            $table->string('color', 20)->nullable();

            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();

            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            // Se a campanha nasceu de uma sugestão do motor de oportunidades
            // (ver MarketingOpportunityService), guarda qual foi — só auditoria/rastreio.
            $table->string('source_opportunity', 30)->nullable();

            $table->timestamps();

            $table->index(['company_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaigns');
    }
};
