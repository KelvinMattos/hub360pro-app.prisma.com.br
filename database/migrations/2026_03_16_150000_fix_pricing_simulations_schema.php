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
        Schema::table('pricing_simulations', function (Blueprint $table) {
            // Ensure company_id exists as it's crucial for tenancy
            if (!Schema::hasColumn('pricing_simulations', 'company_id')) {
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            }

            // Check and add SoftDeletes
            if (!Schema::hasColumn('pricing_simulations', 'deleted_at')) {
                $table->softDeletes();
            }

            if (!Schema::hasColumn('pricing_simulations', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('pricing_simulations', 'product_id')) {
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('pricing_simulations', 'scenario_name')) {
                if (Schema::hasColumn('pricing_simulations', 'title')) {
                    $table->renameColumn('title', 'scenario_name');
                } else {
                    $table->string('scenario_name')->after('id');
                }
            }

            if (!Schema::hasColumn('pricing_simulations', 'marketplace_target')) {
                $table->string('marketplace_target')->nullable();
            }

            // Financial columns
            $financialColumns = [
                'cost' => ['decimal', [15, 2]],
                'freight' => ['decimal', [15, 2]],
                'fixed_fee' => ['decimal', [15, 2]],
                'taxes_percent' => ['decimal', [5, 2]],
                'commission_percent' => ['decimal', [5, 2]],
                'margin_percent' => ['decimal', [5, 2]],
                'suggested_price' => ['decimal', [15, 2]],
                'contribution_margin_value' => ['decimal', [15, 2]],
            ];

            foreach ($financialColumns as $column => $type) {
                if (!Schema::hasColumn('pricing_simulations', $column)) {
                    $table->{$type[0]}($column, ...$type[1])->default(0);
                }
            }

            if (!Schema::hasColumn('pricing_simulations', 'status')) {
                $table->string('status')->default('draft');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pricing_simulations', function (Blueprint $table) {
            // In a real scenario, we might want to drop these, but for a "fix" migration, 
            // it's safer not to or be very specific.
        });
    }
};
