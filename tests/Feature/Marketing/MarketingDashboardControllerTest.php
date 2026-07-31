<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MarketingDashboardControllerTest extends TestCase
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

    public function test_index_shows_opportunities_stage_counts_and_my_tasks(): void
    {
        $productId = DB::table('products')->insertGetId([
            'company_id' => $this->companyId, 'sku' => 'LANC-1', 'title' => 'Lançamento Recente',
            'launched_at' => now()->subDays(5), 'sale_price' => 100, 'stock_quantity' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('marketing_campaigns')->insert([
            'company_id' => $this->companyId, 'name' => 'Campanha A', 'stage' => 'execucao',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('marketing_tasks')->insert([
            'company_id' => $this->companyId, 'assignee_id' => $this->user->id, 'title' => 'Minha tarefa',
            'status' => 'todo', 'priority' => 'alta', 'due_date' => now()->subDay()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('marketing.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Marketing/Dashboard')
            ->where('opportunities.lancamento.0.product_id', $productId)
            ->where('stageCounts.execucao', 1)
            ->where('myTasks.0.title', 'Minha tarefa')
            ->where('overdueCount', 1)
        );
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('marketing.dashboard'));
        $response->assertRedirect(route('login'));
    }
}
