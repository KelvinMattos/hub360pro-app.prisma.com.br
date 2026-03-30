<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $dropFK = function(string $table, string $fk) {
            $exists = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM information_schema.TABLE_CONSTRAINTS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = ? 
                AND CONSTRAINT_NAME = ? 
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            ", [$table, $fk]);

            if (!empty($exists)) {
                Schema::table($table, function (Blueprint $tableAlter) use ($fk) {
                    $tableAlter->dropForeign($fk);
                });
            }
        };

        // Marketplace Questions
        if (Schema::hasTable('marketplace_questions')) {
            $dropFK('marketplace_questions', 'marketplace_questions_integration_id_foreign');
            $dropFK('marketplace_questions', 'marketplace_questions_marketplace_credential_id_foreign');
            
            Schema::table('marketplace_questions', function (Blueprint $table) {
                $table->foreign('integration_id')
                    ->references('id')
                    ->on('integrations')
                    ->cascadeOnDelete();
            });
        }

        // Marketplace Messages
        if (Schema::hasTable('marketplace_messages')) {
            $dropFK('marketplace_messages', 'marketplace_messages_integration_id_foreign');
            $dropFK('marketplace_messages', 'marketplace_messages_marketplace_credential_id_foreign');

            Schema::table('marketplace_messages', function (Blueprint $table) {
                $table->foreign('integration_id')
                    ->references('id')
                    ->on('integrations')
                    ->cascadeOnDelete();
            });
        }

        // Orders
        if (Schema::hasTable('orders')) {
            if (Schema::hasColumn('orders', 'integration_id')) {
                // Ensure column is nullable to support ON DELETE SET NULL
                // General error: 1830 Column 'integration_id' cannot be NOT NULL
                DB::statement("ALTER TABLE `orders` MODIFY `integration_id` BIGINT UNSIGNED NULL");

                $dropFK('orders', 'orders_integration_id_foreign');
                $dropFK('orders', 'orders_marketplace_credential_id_foreign');
                
                Schema::table('orders', function (Blueprint $table) {
                    $table->foreign('integration_id')
                        ->references('id')
                        ->on('integrations')
                        ->nullOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        // No simple rollback to "unsafe" state needed, but could restore restrict if required.
    }
};
