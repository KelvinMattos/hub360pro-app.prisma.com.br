<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('products', function (Blueprint $table) {
            // Identificadores estruturais do ML
            if (!Schema::hasColumn('products', 'category_id')) $table->string('category_id')->nullable()->index();
            if (!Schema::hasColumn('products', 'listing_type_id')) $table->string('listing_type_id')->nullable(); // gold_pro, gold_special
            if (!Schema::hasColumn('products', 'permalink')) $table->string('permalink')->nullable();
            
            // Ficha Técnica Completa (Data Mining)
            if (!Schema::hasColumn('products', 'json_data')) $table->json('json_data')->nullable();
            
            // Status do Anúncio
            if (!Schema::hasColumn('products', 'status')) $table->string('status')->default('active'); // active, paused
        });
    }

    public function down()
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['category_id', 'listing_type_id', 'permalink', 'json_data', 'status']);
        });
    }
};