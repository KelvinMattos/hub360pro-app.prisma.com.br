<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table) {
                if (!Schema::hasColumn('orders', 'billing_info_json')) {
                    $table->longText('billing_info_json')->nullable();
                }
                if (!Schema::hasColumn('orders', 'billing_legal_name')) {
                    $table->string('billing_legal_name')->nullable();
                }
                if (!Schema::hasColumn('orders', 'billing_ie')) {
                    $table->string('billing_ie')->nullable();
                }
            });
        }
    }

    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['billing_info_json', 'billing_legal_name']);
        });
    }
};