<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tarefas do módulo de Marketing — podem pertencer a uma campanha (ligadas
 * ao Kanban) ou existir soltas (`campaign_id` nulo), pra tarefas do dia a
 * dia do time que não viram uma campanha inteira.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_tasks')) {
            return;
        }

        Schema::create('marketing_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->nullable()->index();
            $table->unsignedBigInteger('campaign_id')->nullable()->index();

            $table->string('title', 255);
            $table->text('description')->nullable();

            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->string('status', 20)->default('todo'); // todo|doing|done
            $table->string('priority', 10)->default('media'); // baixa|media|alta
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['assignee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_tasks');
    }
};
