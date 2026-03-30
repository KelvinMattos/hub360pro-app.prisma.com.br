<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketplace_questions')) {
            Schema::table('marketplace_questions', function (Blueprint $table) {
                $table->renameColumn('marketplace_credential_id', 'integration_id');
            });
        }
        
        if (Schema::hasTable('marketplace_messages')) {
            Schema::table('marketplace_messages', function (Blueprint $table) {
                $table->renameColumn('marketplace_credential_id', 'integration_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marketplace_questions')) {
            Schema::table('marketplace_questions', function (Blueprint $table) {
                $table->renameColumn('integration_id', 'marketplace_credential_id');
            });
        }
        
        if (Schema::hasTable('marketplace_messages')) {
            Schema::table('marketplace_messages', function (Blueprint $table) {
                $table->renameColumn('integration_id', 'marketplace_credential_id');
            });
        }
    }
};
