<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Force fix schemas for Pricing Simulation error
        if (!Schema::hasTable('pricing_simulations')) {
            Schema::create('pricing_simulations', function (Blueprint $table) {
                $table->id();
                $table->string('status')->default('active');
                $table->foreignId('company_id')->constrained();
                $table->string('title');
                $table->json('data')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Schema::dropIfExists('pricing_simulations');
    }
};
