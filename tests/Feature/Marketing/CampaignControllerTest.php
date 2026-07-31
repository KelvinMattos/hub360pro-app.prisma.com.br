<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CampaignControllerTest extends TestCase
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

    private function makeProduct(array $overrides = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'company_id' => $this->companyId, 'sku' => 'SKU-' . uniqid(), 'title' => 'Produto Teste',
            'sale_price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_index_lists_only_company_campaigns(): void
    {
        DB::table('marketing_campaigns')->insert([
            'company_id' => $this->companyId, 'name' => 'Minha Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('marketing_campaigns')->insert([
            'company_id' => $otherCompany, 'name' => 'De Outra Empresa', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('marketing.campaigns.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Marketing/Campaigns/Kanban')
            ->where('campaigns.0.name', 'Minha Campanha')
            ->has('campaigns', 1)
        );
    }

    public function test_store_creates_campaign_in_ideia_stage(): void
    {
        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.store'), [
            'name' => 'Campanha Black Friday', 'type' => 'sazonal',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('marketing_campaigns', [
            'company_id' => $this->companyId, 'name' => 'Campanha Black Friday', 'stage' => 'ideia', 'type' => 'sazonal',
        ]);
    }

    public function test_create_from_opportunity_creates_campaign_and_links_products(): void
    {
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-opportunity'), [
            'opportunity' => 'liquidar', 'name' => 'Liquidação de Inverno', 'product_ids' => [$p1, $p2],
        ]);

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('name', 'Liquidação de Inverno')->first();
        $this->assertNotNull($campaign);
        $this->assertSame('liquidacao', $campaign->type);
        $this->assertSame('liquidar', $campaign->source_opportunity);

        $this->assertSame(2, DB::table('marketing_campaign_products')->where('campaign_id', $campaign->id)->count());
        $this->assertDatabaseHas('marketing_campaign_products', [
            'campaign_id' => $campaign->id, 'product_id' => $p1, 'suggested_action' => 'liquidar',
        ]);
    }

    public function test_create_from_opportunity_ignores_products_from_another_company(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $foreignProduct = DB::table('products')->insertGetId([
            'company_id' => $otherCompany, 'sku' => 'FOREIGN', 'title' => 'De outra empresa',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-opportunity'), [
            'opportunity' => 'lancamento', 'name' => 'Teste', 'product_ids' => [$foreignProduct],
        ]);

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('name', 'Teste')->first();
        $this->assertSame(0, DB::table('marketing_campaign_products')->where('campaign_id', $campaign->id)->count());
    }

    public function test_update_stage_moves_campaign_on_kanban(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->patch(route('marketing.campaigns.stage', $campaignId), ['stage' => 'execucao']);

        $response->assertRedirect();
        $this->assertDatabaseHas('marketing_campaigns', ['id' => $campaignId, 'stage' => 'execucao']);
    }

    public function test_update_stage_rejects_unknown_stage(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->patch(route('marketing.campaigns.stage', $campaignId), ['stage' => 'inexistente']);

        $response->assertSessionHasErrors('stage');
    }

    public function test_update_stage_cannot_be_applied_to_another_companys_campaign(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $otherCompany, 'name' => 'De outra empresa', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->patch(route('marketing.campaigns.stage', $campaignId), ['stage' => 'execucao']);

        $response->assertNotFound();
    }

    public function test_attach_and_detach_product(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $productId = $this->makeProduct();

        $this->actingAs($this->user)->post(route('marketing.campaigns.products.attach', $campaignId), [
            'product_id' => $productId, 'suggested_action' => 'destacar',
        ]);
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaignId, 'product_id' => $productId]);

        $this->actingAs($this->user)->delete(route('marketing.campaigns.products.detach', [$campaignId, $productId]));
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaignId, 'product_id' => $productId]);
    }

    public function test_destroy_detaches_tasks_instead_of_deleting_them(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $taskId = DB::table('marketing_tasks')->insertGetId([
            'company_id' => $this->companyId, 'campaign_id' => $campaignId, 'title' => 'Tarefa',
            'status' => 'todo', 'priority' => 'media', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->delete(route('marketing.campaigns.destroy', $campaignId));

        $this->assertDatabaseMissing('marketing_campaigns', ['id' => $campaignId]);
        $this->assertDatabaseHas('marketing_tasks', ['id' => $taskId, 'campaign_id' => null]);
    }

    public function test_show_returns_404_for_campaign_from_another_company(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $otherCompany, 'name' => 'De outra empresa', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('marketing.campaigns.show', $campaignId));

        $response->assertNotFound();
    }

    public function test_search_products_matches_by_title_or_sku(): void
    {
        $this->makeProduct(['sku' => 'ABC-123', 'title' => 'Tênis Corrida']);
        $this->makeProduct(['sku' => 'XYZ-999', 'title' => 'Camiseta Básica']);

        $response = $this->actingAs($this->user)
            ->getJson(route('marketing.campaigns.products.search', ['q' => 'Corrida']));

        $response->assertOk();
        $this->assertCount(1, $response->json('products'));
        $this->assertSame('Tênis Corrida', $response->json('products.0.title'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('marketing.campaigns.index'));
        $response->assertRedirect(route('login'));
    }
}
