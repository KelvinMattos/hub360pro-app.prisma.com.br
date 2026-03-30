<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->string('marketplace_item_id');
            $table->string('strategy'); // follow_cheapest, fixed_difference, percentage_margin
            $table->decimal('value', 15, 2);
            $table->decimal('min_price', 15, 2);
            $table->decimal('max_price', 15, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_applied_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_rules');
    }
};
