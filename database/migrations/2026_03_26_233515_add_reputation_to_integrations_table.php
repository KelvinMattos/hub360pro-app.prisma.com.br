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
        Schema::table('integrations', function (Blueprint $table) {
            $table->string('reputation_level')->nullable()->after('status');
            $table->string('power_seller_status')->nullable()->after('reputation_level');
            $table->decimal('cancellation_rate', 5, 2)->default(0)->after('power_seller_status');
            $table->decimal('delayed_handling_rate', 5, 2)->default(0)->after('cancellation_rate');
            $table->decimal('claims_rate', 5, 2)->default(0)->after('delayed_handling_rate');
            $table->json('reputation_metrics')->nullable()->after('claims_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            //
        });
    }
};
