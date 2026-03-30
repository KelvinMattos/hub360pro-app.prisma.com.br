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
        Schema::table('pricing_simulations', function (Blueprint $table) {
            if (!Schema::hasColumn('pricing_simulations', 'status')) {
                $table->string('status')->default('active')->after('id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_simulations', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
