<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('marketplace_credential_id')->constrained();
            $table->string('external_id')->unique();
            $table->string('product_external_id');
            $table->text('question_text');
            $table->text('answer_text')->nullable();
            $table->string('status')->default('unanswered');
            $table->string('buyer_username')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_questions');
    }
};
