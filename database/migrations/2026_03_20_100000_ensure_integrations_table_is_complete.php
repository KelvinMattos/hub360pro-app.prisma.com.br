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
        // 1. Create table if not exists
        if (!Schema::hasTable('integrations')) {
            Schema::create('integrations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('platform')->index();
                $table->timestamps();
            });
        }

        // 2. Add columns safely
        Schema::table('integrations', function (Blueprint $table) {
            if (!Schema::hasColumn('integrations', 'app_id')) {
                $table->string('app_id')->nullable()->after('platform');
            }
            if (!Schema::hasColumn('integrations', 'client_secret')) {
                $table->string('client_secret')->nullable()->after('app_id');
            }
            if (!Schema::hasColumn('integrations', 'status')) {
                $table->string('status')->default('pending_auth')->after('client_secret');
            }
            if (!Schema::hasColumn('integrations', 'seller_id')) {
                $table->string('seller_id')->nullable()->index()->after('status');
            }
            if (!Schema::hasColumn('integrations', 'access_token')) {
                $table->text('access_token')->nullable()->after('seller_id');
            }
            if (!Schema::hasColumn('integrations', 'refresh_token')) {
                $table->text('refresh_token')->nullable()->after('access_token');
            }
            if (!Schema::hasColumn('integrations', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('refresh_token');
            }
            if (!Schema::hasColumn('integrations', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable()->after('expires_at');
            }
            if (!Schema::hasColumn('integrations', 'external_user_id')) {
                $table->string('external_user_id')->nullable()->after('token_expires_at');
            }
            if (!Schema::hasColumn('integrations', 'external_nickname')) {
                $table->string('external_nickname')->nullable()->after('external_user_id');
            }
            if (!Schema::hasColumn('integrations', 'account_nickname')) {
                $table->string('account_nickname')->nullable()->after('external_nickname');
            }
            if (!Schema::hasColumn('integrations', 'redirect_uri')) {
                $table->string('redirect_uri')->nullable()->after('account_nickname');
            }
            if (!Schema::hasColumn('integrations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('redirect_uri');
            }
            if (!Schema::hasColumn('integrations', 'auto_fetch_fees')) {
                $table->boolean('auto_fetch_fees')->default(false)->after('is_active');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop columns in production to avoid data loss
    }
};
