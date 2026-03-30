<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('product_medias')) {
            Schema::create('product_medias', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->string('url')->nullable();
                $table->integer('position')->default(0);
                $table->string('type')->default('image'); // image, video
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('product_channel_settings')) {
            Schema::create('product_channel_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('product_id')->constrained()->cascadeOnDelete();
                $table->foreignId('integration_id')->nullable()->constrained()->onDelete('set null');
                $table->string('listing_type')->nullable(); // classic, premium, standard
                $table->decimal('custom_price', 15, 2)->nullable();
                $table->decimal('custom_promotional_price', 15, 2)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_settings');
        Schema::dropIfExists('product_medias');
    }
};
