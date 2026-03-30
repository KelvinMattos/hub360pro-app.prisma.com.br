<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            if (!Schema::hasColumn('financial_reports', 'name')) {
                $table->string('name')->after('id');
            }
            if (!Schema::hasColumn('financial_reports', 'type')) {
                $table->string('type')->default('dre')->after('name');
            }
            if (!Schema::hasColumn('financial_reports', 'start_date')) {
                $table->date('start_date')->after('type');
            }
            if (!Schema::hasColumn('financial_reports', 'end_date')) {
                $table->date('end_date')->after('start_date');
            }
            if (!Schema::hasColumn('financial_reports', 'data')) {
                $table->json('data')->after('end_date');
            }
        });
    }

    public function down(): void
    {
        Schema::table('financial_reports', function (Blueprint $table) {
            $table->dropColumn(['name', 'type', 'start_date', 'end_date', 'data']);
        });
    }
};