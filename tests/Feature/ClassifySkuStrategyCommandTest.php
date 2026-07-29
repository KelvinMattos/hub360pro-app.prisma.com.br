<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ClassifySkuStrategyCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_classifies_only_the_given_company(): void
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

        $this->artisan('sku:classify-strategy', ['--company' => $companyA])
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('sku_strategy')->where('company_id', $companyA)->count());
        $this->assertSame(0, DB::table('sku_strategy')->where('company_id', $companyB)->count());
    }

    public function test_command_classifies_all_companies_without_filter(): void
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

        $this->artisan('sku:classify-strategy')->assertExitCode(0);

        $this->assertSame(1, DB::table('sku_strategy')->where('company_id', $companyA)->count());
        $this->assertSame(1, DB::table('sku_strategy')->where('company_id', $companyB)->count());
    }
}
