<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repricing: margem mínima por marca + auditoria completa de cada alteração,
 * agrupada em lotes para permitir rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('brand_margins')) {
            Schema::create('brand_margins', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->string('brand');
                $table->decimal('min_margin_pct', 8, 3)->default(10);
                $table->timestamps();
                $table->unique(['company_id', 'brand']);
            });
        }

        if (!Schema::hasTable('repricing_batches')) {
            Schema::create('repricing_batches', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->boolean('dry_run')->default(true);
                $table->integer('evaluated')->default(0);   // avaliados
                $table->integer('changed')->default(0);     // efetivamente alterados
                $table->integer('skipped')->default(0);     // barrados pelas travas
                $table->boolean('rolled_back')->default(false);
                $table->timestamp('rolled_back_at')->nullable();
                $table->json('settings')->nullable();       // travas usadas na execução
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('repricing_logs')) {
            Schema::create('repricing_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('batch_id')->index();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->unsignedBigInteger('product_id')->index();
                $table->decimal('price_before', 12, 2)->nullable();
                $table->decimal('price_after', 12, 2)->nullable();
                $table->decimal('market_price', 12, 2)->nullable();
                $table->string('market_source', 40)->nullable();   // origem do preço de mercado
                $table->timestamp('market_checked_at')->nullable(); // idade do dado usado
                $table->string('action', 20)->default('applied');  // applied | skipped | rolled_back
                $table->string('reason', 200)->nullable();         // por que foi barrado
                $table->boolean('applied')->default(false);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('repricing_logs');
        Schema::dropIfExists('repricing_batches');
        Schema::dropIfExists('brand_margins');
    }
};
