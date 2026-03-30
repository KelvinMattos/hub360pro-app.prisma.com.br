<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('integrations', 'account_nickname')) {
                $table->string('account_nickname')->nullable()->after('platform');
            }
            if (!Schema::hasColumn('integrations', 'seller_id')) {
                $table->string('seller_id')->nullable()->after('account_nickname');
            }
            if (!Schema::hasColumn('integrations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('seller_id');
            }
            if (!Schema::hasColumn('integrations', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('integrations', 'external_user_id')) {
                $table->string('external_user_id')->nullable()->after('seller_id');
            }
            if (!Schema::hasColumn('integrations', 'external_nickname')) {
                $table->string('external_nickname')->nullable()->after('external_user_id');
            }
            if (!Schema::hasColumn('integrations', 'redirect_uri')) {
                $table->string('redirect_uri')->nullable()->after('platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('integrations', function (Blueprint $table) {
            $table->dropColumn([
                'account_nickname',
                'seller_id',
                'is_active',
                'token_expires_at',
                'external_user_id',
                'external_nickname',
                'redirect_uri'
            ]);
        });
    }
};
