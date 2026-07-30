<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ComputeReplenishmentPlanCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_computes_only_the_given_company(): void
    {
        $companyA = DB::table('companies')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $companyB = DB::table('companies')->insertGetId(['name' => 'B', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'company_id' => $companyA, 'sku' => 'A1', 'title' => 'Produto A',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $companyB, 'sku' => 'B1', 'title' => 'Produto B',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('inventory:compute-replenishment', ['--company' => $companyA])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('replenishment_plan')->where('company_id', $companyA)->count());
        $this->assertSame(0, DB::table('replenishment_plan')->where('company_id', $companyB)->count());
    }

    public function test_command_computes_all_companies_without_filter(): void
    {
        $companyA = DB::table('companies')->insertGetId(['name' => 'A', 'created_at' => now(), 'updated_at' => now()]);
        $companyB = DB::table('companies')->insertGetId(['name' => 'B', 'created_at' => now(), 'updated_at' => now()]);

        DB::table('products')->insert([
            'company_id' => $companyA, 'sku' => 'A1', 'title' => 'Produto A',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('products')->insert([
            'company_id' => $companyB, 'sku' => 'B1', 'title' => 'Produto B',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('inventory:compute-replenishment')->assertExitCode(0);

        $this->assertSame(1, DB::table('replenishment_plan')->where('company_id', $companyA)->count());
        $this->assertSame(1, DB::table('replenishment_plan')->where('company_id', $companyB)->count());
    }
}
