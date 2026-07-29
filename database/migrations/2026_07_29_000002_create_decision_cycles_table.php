<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de decisão: testa UMA mudança de preço (definida em `limits.price_change_pct`)
 * de forma gradual e reversível sobre um escopo de SKUs, medindo o ROI real
 * (diferenças-em-diferenças contra um grupo de controle retirado do próprio
 * escopo) e com freio automático por violação de piso ou queda de volume.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('decision_cycles')) {
            Schema::create('decision_cycles', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('created_by')->nullable();

                $table->string('objective', 255);
                $table->json('scope');   // critérios: product_ids | brand | pricing_role
                $table->json('limits');  // price_change_pct, min_margin_pct, max_volume_drop_pct, batch_size, control_pct
                $table->integer('duration_days')->default(30);
                $table->decimal('estimated_gain', 12, 2)->nullable();

                $table->string('status', 20)->default('draft'); // draft|simulated|running|aborted|completed

                $table->json('treatment_product_ids')->nullable();
                $table->json('control_product_ids')->nullable();
                $table->json('applied_product_ids')->nullable();
                $table->json('baseline_snapshot')->nullable();
                $table->json('simulation_result')->nullable();
                $table->json('roi_result')->nullable();

                $table->string('abort_reason', 255)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();

                $table->timestamps();
            });
        }

        if (!Schema::hasTable('decision_cycle_logs')) {
            Schema::create('decision_cycle_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('decision_cycle_id')->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->decimal('price_before', 12, 2)->nullable();
                $table->decimal('price_after', 12, 2)->nullable();
                $table->string('action', 20)->default('applied'); // applied|blocked
                $table->string('reason', 200)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('decision_cycle_logs');
        Schema::dropIfExists('decision_cycles');
    }
};
