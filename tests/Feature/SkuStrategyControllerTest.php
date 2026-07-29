<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SkuStrategyClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SkuStrategyControllerTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);
    }

    public function test_index_shows_empty_state_before_first_classification(): void
    {
        $response = $this->actingAs($this->user)->get(route('segmentation.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Segmentation/Index')
            ->where('has_data', false)
            ->where('last_computed_at', null)
            ->where('rows', [])
        );
    }

    public function test_index_lists_classified_skus_after_job_runs(): void
    {
        DB::table('products')->insert([
            'company_id' => $this->companyId, 'sku' => 'ABC', 'title' => 'Produto Um',
            'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $response = $this->actingAs($this->user)->get(route('segmentation.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Segmentation/Index')
            ->where('has_data', true)
            ->where('total', 1)
            ->where('rows.0.title', 'Produto Um')
            ->where('rows.0.margin_pct', 50)
        );
    }

    public function test_index_filters_by_pricing_role(): void
    {
        $p1 = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'A', 'title' => 'A',
            'sale_price' => 200, 'cost_price' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $p2 = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'B', 'title' => 'B',
            'sale_price' => 100, 'cost_price' => 95, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(SkuStrategyClassifier::class)->classifyCompany($this->companyId);

        $roleOfA = DB::table('sku_strategy')->where('product_id', $p1)->value('pricing_role');

        $response = $this->actingAs($this->user)
            ->get(route('segmentation.index', ['pricing_role' => $roleOfA]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('total', 1)
            ->where('rows.0.title', 'A')
        );
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get(route('segmentation.index'));
        $response->assertRedirect(route('login'));
    }
}
