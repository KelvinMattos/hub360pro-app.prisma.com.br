<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('order_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('title'); // Ex: Venda Aprovada
            $table->text('message'); // Ex: O pedido #123 foi pago.
            $table->string('type')->default('info'); // info, success, warning, danger
            $table->string('external_id')->nullable(); // ID do ML
            $table->boolean('is_read')->default(false);
            $table->timestamps();
            
            $table->index(['company_id', 'is_read']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('order_notifications');
    }
};