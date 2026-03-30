<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add columns if they don't exist (Idempotency)
        Schema::table('marketplace_credentials', function (Blueprint $table) {
            if (!Schema::hasColumn('marketplace_credentials', 'account_nickname')) {
                $table->string('account_nickname')->nullable()->after('marketplace');
            }
            if (!Schema::hasColumn('marketplace_credentials', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('account_nickname');
            }
        });

        // 2. Add temporary index for company_id to satisfy FK requirement
        // We do this in a separate call to ensure it exists before we drop the unique one.
        try {
            Schema::table('marketplace_credentials', function (Blueprint $table) {
                $table->index('company_id', 'creds_company_index_temp');
            });
        } catch (\Exception $e) {
            // Index might already exist
        }

        // 3. Drop the old unique index
        try {
            Schema::table('marketplace_credentials', function (Blueprint $table) {
                $table->dropUnique('marketplace_credentials_company_id_marketplace_unique');
            });
        } catch (\Exception $e) {
            // Already dropped or different name
        }

        // 4. Add the new composite unique index
        try {
            Schema::table('marketplace_credentials', function (Blueprint $table) {
                $table->unique(['company_id', 'marketplace', 'account_nickname'], 'marketplace_accounts_unique');
            });
        } catch (\Exception $e) {
            // Already exists
        }
    }

    public function down(): void
    {
        Schema::table('marketplace_credentials', function (Blueprint $table) {
            try { $table->dropUnique('marketplace_accounts_unique'); } catch (\Exception $e) {}
            
            try {
                $table->unique(['company_id', 'marketplace'], 'marketplace_credentials_company_id_marketplace_unique');
            } catch (\Exception $e) {}

            $table->dropColumn(['account_nickname', 'is_active']);
            
            try { $table->dropIndex('creds_company_index_temp'); } catch (\Exception $e) {}
        });
    }
};
