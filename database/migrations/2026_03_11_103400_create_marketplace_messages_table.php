<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained();
            $table->foreignId('marketplace_credential_id')->constrained();
            $table->foreignId('order_id')->constrained();
            $table->string('external_id')->unique();
            $table->string('sender_id');
            $table->text('text');
            $table->enum('direction', ['inbound', 'outbound']);
            $table->string('status')->default('sent');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_messages');
    }
};
