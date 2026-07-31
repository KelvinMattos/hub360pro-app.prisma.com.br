<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Produtos vinculados a uma campanha de marketing, com a ação sugerida/registrada para cada um. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaign_products')) {
            return;
        }

        Schema::create('marketing_campaign_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->unsignedBigInteger('product_id')->index();

            $table->string('suggested_action', 30)->nullable(); // destacar|anunciar|liquidar|repor
            $table->decimal('discount_pct', 5, 2)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_products');
    }
};
