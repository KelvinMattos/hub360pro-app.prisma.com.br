<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillNetProfitTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_recalculates_net_profit_for_all_orders(): void
    {
        $id1 = $this->insertRawOrder(['total_amount' => 1000, 'cost_fee_commission' => 140, 'cost_fee_taxes' => 60, 'cost_fee_shipping' => 25, 'cost_fee_fixed' => 6, 'cost_products' => 400]);
        $id2 = $this->insertRawOrder(['total_amount' => 200, 'cost_fee_commission' => 10, 'cost_fee_taxes' => 5, 'cost_fee_shipping' => 0, 'cost_fee_fixed' => 6, 'cost_products' => 80]);

        $this->artisan('orders:backfill-net-profit')->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $id1, 'net_profit' => 369.00]);
        $this->assertDatabaseHas('orders', ['id' => $id2, 'net_profit' => 99.00]);
    }

    public function test_backfill_respects_company_filter(): void
    {
        $companyA = DB::table('companies')->insertGetId(['name' => 'Empresa A', 'created_at' => now(), 'updated_at' => now()]);
        $companyB = DB::table('companies')->insertGetId(['name' => 'Empresa B', 'created_at' => now(), 'updated_at' => now()]);

        $idA = $this->insertRawOrder(['company_id' => $companyA, 'total_amount' => 100, 'cost_products' => 50]);
        $idB = $this->insertRawOrder(['company_id' => $companyB, 'total_amount' => 300, 'cost_products' => 100]);

        $this->artisan('orders:backfill-net-profit', ['--company' => $companyA])->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $idA, 'net_profit' => 50.00]);
        // A empresa 2 não foi tocada: net_profit continua nulo.
        $this->assertDatabaseHas('orders', ['id' => $idB, 'net_profit' => null]);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $id = $this->insertRawOrder(['total_amount' => 500, 'cost_products' => 200]);

        $this->artisan('orders:backfill-net-profit', ['--dry-run' => true])->assertExitCode(0);

        $this->assertDatabaseHas('orders', ['id' => $id, 'net_profit' => null]);
    }

    /** Reproduz o bug original: SUM(net_profit) não deve mais estourar "Unknown column". */
    public function test_report_controller_sum_query_no_longer_throws(): void
    {
        $this->insertRawOrder(['total_amount' => 300, 'cost_products' => 100, 'status' => 'paid']);
        $this->artisan('orders:backfill-net-profit');

        $result = DB::table('orders')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('SUM(net_profit) as profit')
            ->first();

        $this->assertNotNull($result);
        $this->assertIsNumeric($result->profit);
    }

    private function insertRawOrder(array $overrides = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'company_id' => null,
            'status' => 'paid',
            'total_amount' => 0,
            'cost_fee_commission' => 0,
            'cost_fee_taxes' => 0,
            'cost_fee_shipping' => 0,
            'cost_fee_fixed' => 0,
            'cost_products' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
