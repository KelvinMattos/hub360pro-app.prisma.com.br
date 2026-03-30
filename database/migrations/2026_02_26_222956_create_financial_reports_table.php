<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::dropIfExists('financial_reports');

        Schema::create('financial_reports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('dre'); // dre, cash_flow
            $table->date('start_date');
            $table->date('end_date');
            $table->json('data');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reports');
    }
};