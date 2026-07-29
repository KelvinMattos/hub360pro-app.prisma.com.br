<?php

namespace Tests\Feature;

use App\Models\DecisionCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TickDecisionCyclesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_ignores_cycles_not_running(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        DecisionCycle::create([
            'company_id' => $companyId,
            'objective' => 'Teste',
            'scope' => ['brand' => 'X'],
            'limits' => ['price_change_pct' => 5],
            'duration_days' => 7,
            'status' => DecisionCycle::STATUS_DRAFT,
        ]);

        $this->artisan('decision-cycles:tick')->assertExitCode(0);
    }

    public function test_command_ticks_running_cycles(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $productId = DB::table('products')->insertGetId([
            'company_id' => $companyId, 'sku' => 'X1', 'title' => 'Produto', 'brand' => 'X',
            'price' => 100, 'cost_price' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $cycle = DecisionCycle::create([
            'company_id' => $companyId,
            'objective' => 'Teste',
            'scope' => ['product_ids' => [$productId]],
            'limits' => ['price_change_pct' => 5, 'min_margin_pct' => 10, 'control_pct' => 0, 'batch_size' => 10],
            'duration_days' => 7,
            'status' => DecisionCycle::STATUS_RUNNING,
            'treatment_product_ids' => [$productId],
            'control_product_ids' => [],
            'applied_product_ids' => [],
            'baseline_snapshot' => ['treatment' => ['revenue' => 0, 'profit' => 0, 'volume' => 0], 'control' => ['revenue' => 0, 'profit' => 0, 'volume' => 0], 'days' => 30],
            'started_at' => now(),
        ]);

        $this->artisan('decision-cycles:tick')->assertExitCode(0);

        $this->assertDatabaseHas('decision_cycle_logs', ['decision_cycle_id' => $cycle->id, 'product_id' => $productId, 'action' => 'applied']);
    }
}
