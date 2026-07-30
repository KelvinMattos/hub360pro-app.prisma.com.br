<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SuperAdminController e MarketplaceIntelligenceService já referenciavam
 * `system_ai_keys` e `marketplace_benchmark_rates` (DB::table), mas nenhuma
 * migration criava essas tabelas — a tela Admin/AiConfig sempre respondia
 * 500 (tabela inexistente). Criadas aqui a partir do uso real no código.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('system_ai_keys')) {
            Schema::create('system_ai_keys', function (Blueprint $table) {
                $table->id();
                $table->string('provider');
                $table->text('api_key');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('error_count')->default(0);
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('marketplace_benchmark_rates')) {
            Schema::create('marketplace_benchmark_rates', function (Blueprint $table) {
                $table->id();
                $table->string('platform');
                $table->string('listing_type');
                $table->decimal('commission_percent', 8, 2)->default(0);
                $table->decimal('fixed_fee', 10, 2)->default(0);
                $table->decimal('fee_threshold', 10, 2)->default(0);
                $table->timestamp('last_check_at')->nullable();
                $table->string('updated_via')->nullable();
                $table->timestamps();

                $table->unique(['platform', 'listing_type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_benchmark_rates');
        Schema::dropIfExists('system_ai_keys');
    }
};
