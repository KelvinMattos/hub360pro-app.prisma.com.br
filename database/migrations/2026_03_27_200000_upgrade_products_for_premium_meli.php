<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'video_id')) {
                $table->string('video_id')->nullable()->after('thumbnail');
            }
            if (!Schema::hasColumn('products', 'shipping_mode')) {
                $table->string('shipping_mode')->nullable()->after('video_id');
            }
            if (!Schema::hasColumn('products', 'free_shipping')) {
                $table->boolean('free_shipping')->default(false)->after('shipping_mode');
            }
            if (!Schema::hasColumn('products', 'variations')) {
                $table->json('variations')->nullable()->after('attributes');
            }
            if (!Schema::hasColumn('products', 'listing_type_id')) {
                $table->string('listing_type_id')->nullable()->after('listing_type');
            }
            if (!Schema::hasColumn('products', 'sale_fee')) {
                $table->decimal('sale_fee', 10, 2)->nullable()->after('price');
            }
            if (!Schema::hasColumn('products', 'last_sync_at')) {
                $table->timestamp('last_sync_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'video_id', 'shipping_mode', 'free_shipping', 
                'variations', 'listing_type_id', 'sale_fee', 'last_sync_at'
            ]);
        });
    }
};
