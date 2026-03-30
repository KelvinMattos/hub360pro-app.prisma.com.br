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
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sale_price')) {
                // If there's an existing 'price' column, place it right after, otherwise just add it
                if (Schema::hasColumn('products', 'price')) {
                    $table->decimal('sale_price', 15, 2)->nullable()->after('price');
                } else {
                    $table->decimal('sale_price', 15, 2)->nullable();
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'sale_price')) {
                $table->dropColumn('sale_price');
            }
        });
    }
};
