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

    private function makeReplenishmentRow(int $productId, array $overrides = []): void
    {
        DB::table('replenishment_plan')->insert(array_merge([
            'company_id' => $this->companyId, 'product_id' => $productId,
            'sku' => 'SKU', 'title' => 'Produto', 'brand' => null,
            'stock' => 10, 'cost_price' => 50, 'sale_price' => 100,
            'velocity_weighted' => 0, 'status' => 'saudavel',
            'computed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function makeCommercialDate(array $overrides = []): int
    {
        return DB::table('commercial_dates')->insertGetId(array_merge([
            'company_id' => null, 'date' => '2026-11-27', 'title' => 'Black Friday',
            'category' => 'sazonal', 'recurring_yearly' => true, 'source' => 'seed',
            'created_at' => now(), 'updated_at' => now(),
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

    public function test_create_from_date_bundles_best_sellers_and_liquidation_candidates(): void
    {
        $bestSeller = $this->makeProduct();
        $deadStock = $this->makeProduct();
        $this->makeReplenishmentRow($bestSeller, ['abc_class' => 'A', 'revenue_30d' => 5000]);
        $this->makeReplenishmentRow($deadStock, ['status' => 'estoque_morto', 'immobilized_value' => 3000]);
        $dateId = $this->makeCommercialDate();

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertNotNull($campaign);
        $this->assertSame('sazonal', $campaign->type);
        $this->assertStringContainsString('Black Friday', $campaign->name);

        $this->assertDatabaseHas('marketing_campaign_products', [
            'campaign_id' => $campaign->id, 'product_id' => $bestSeller, 'suggested_action' => 'anunciar',
        ]);
        $this->assertDatabaseHas('marketing_campaign_products', [
            'campaign_id' => $campaign->id, 'product_id' => $deadStock, 'suggested_action' => 'liquidar',
        ]);
    }

    public function test_create_from_date_excludes_opposite_gender_products_for_dia_dos_pais(): void
    {
        $mensShoe = $this->makeProduct();
        $womensShoe = $this->makeProduct();
        $this->makeReplenishmentRow($mensShoe, ['title' => 'Tenis Masculino Corrida', 'abc_class' => 'A', 'revenue_30d' => 5000]);
        $this->makeReplenishmentRow($womensShoe, ['title' => 'Tenis Feminino Corrida', 'abc_class' => 'A', 'revenue_30d' => 9000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia dos Pais', 'audience' => 'masculino']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $mensShoe]);
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $womensShoe]);
    }

    public function test_create_from_date_excludes_opposite_gender_products_for_dia_das_maes(): void
    {
        $mensShirt = $this->makeProduct();
        $womensShirt = $this->makeProduct();
        $this->makeReplenishmentRow($mensShirt, ['title' => 'Camiseta Masculina Basica', 'abc_class' => 'A', 'revenue_30d' => 4000]);
        $this->makeReplenishmentRow($womensShirt, ['title' => 'Camiseta Feminina Basica', 'abc_class' => 'A', 'revenue_30d' => 7000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia das Mães', 'audience' => 'feminino']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $womensShirt]);
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $mensShirt]);
    }

    public function test_create_from_date_excludes_infantil_products_from_dia_dos_pais(): void
    {
        $adultShoe = $this->makeProduct();
        $kidsShoe = $this->makeProduct();
        $this->makeReplenishmentRow($adultShoe, ['title' => 'Tenis Masculino Corrida', 'abc_class' => 'A', 'revenue_30d' => 5000]);
        $this->makeReplenishmentRow($kidsShoe, ['title' => 'Tenis Infantil Menino', 'abc_class' => 'A', 'revenue_30d' => 8000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia dos Pais', 'audience' => 'masculino']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $adultShoe]);
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $kidsShoe]);
    }

    public function test_create_from_date_requires_infantil_marker_for_dia_das_criancas(): void
    {
        $kidsToy = $this->makeProduct();
        $adultMens = $this->makeProduct();
        $adultWomens = $this->makeProduct();
        $this->makeReplenishmentRow($kidsToy, ['title' => 'Bicicleta Infantil Aro 16', 'abc_class' => 'A', 'revenue_30d' => 5000]);
        $this->makeReplenishmentRow($adultMens, ['title' => 'Tenis Masculino Corrida', 'abc_class' => 'A', 'revenue_30d' => 9000]);
        $this->makeReplenishmentRow($adultWomens, ['title' => 'Tenis Feminino Corrida', 'abc_class' => 'A', 'revenue_30d' => 9000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia das Crianças', 'audience' => 'infantil']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $kidsToy]);
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $adultMens]);
        $this->assertDatabaseMissing('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $adultWomens]);
    }

    public function test_create_from_date_infantil_matches_accented_and_synonym_keywords(): void
    {
        $crianca = $this->makeProduct();
        $juvenil = $this->makeProduct();
        $kids = $this->makeProduct();
        $this->makeReplenishmentRow($crianca, ['title' => 'Mochila para Criança', 'abc_class' => 'A', 'revenue_30d' => 3000]);
        $this->makeReplenishmentRow($juvenil, ['title' => 'Bicicleta Juvenil Aro 20', 'status' => 'excesso', 'immobilized_value' => 2000]);
        $this->makeReplenishmentRow($kids, ['title' => 'Tenis Kids Corrida', 'abc_class' => 'A', 'revenue_30d' => 1000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia das Crianças', 'audience' => 'infantil']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $crianca]);
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $juvenil]);
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $kids]);
    }

    public function test_create_from_date_does_not_filter_neutral_titles_by_audience(): void
    {
        $neutralItem = $this->makeProduct();
        $this->makeReplenishmentRow($neutralItem, ['title' => 'Bola De Beach Tennis 03 Unidades', 'abc_class' => 'A', 'revenue_30d' => 3000]);
        $dateId = $this->makeCommercialDate(['title' => 'Dia dos Pais', 'audience' => 'masculino']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $neutralItem]);
    }

    public function test_create_from_date_no_audience_filter_for_neutral_date(): void
    {
        $womensShoe = $this->makeProduct();
        $this->makeReplenishmentRow($womensShoe, ['title' => 'Tenis Feminino Corrida', 'abc_class' => 'A', 'revenue_30d' => 5000]);
        $dateId = $this->makeCommercialDate(['title' => 'Black Friday', 'audience' => null]);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertDatabaseHas('marketing_campaign_products', ['campaign_id' => $campaign->id, 'product_id' => $womensShoe]);
    }

    public function test_create_from_date_dedupes_product_in_both_lists(): void
    {
        // Curva A que também está com excesso de estoque: aparece nas duas listas do motor.
        $productId = $this->makeProduct();
        $this->makeReplenishmentRow($productId, ['abc_class' => 'A', 'revenue_30d' => 5000, 'status' => 'excesso', 'immobilized_value' => 9000]);
        $dateId = $this->makeCommercialDate();

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $campaign = DB::table('marketing_campaigns')->where('source_opportunity', 'calendario')->first();
        $this->assertSame(1, DB::table('marketing_campaign_products')->where('campaign_id', $campaign->id)->where('product_id', $productId)->count());
    }

    public function test_create_from_date_fails_gracefully_without_eligible_products(): void
    {
        $dateId = $this->makeCommercialDate();

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('marketing_campaigns')->count());
    }

    public function test_create_from_date_404_for_date_from_another_company(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $dateId = $this->makeCommercialDate(['company_id' => $otherCompany, 'source' => 'manual']);

        $response = $this->actingAs($this->user)->post(route('marketing.campaigns.from-date', $dateId));

        $response->assertNotFound();
    }

    /**
     * Alguns hosts/proxies na frente da produção filtram verbos HTTP não-padrão
     * (ver CLAUDE.md §6.3, Cloudflare na frente da origem) — o Kanban passou a
     * mandar POST + "?_method=PATCH" na query string em vez de um PATCH literal
     * (resources/js/lib/spoofedRouter.js). Este teste garante que o mecanismo
     * nativo do Symfony/Laravel realmente aceita esse spoof pra essa rota.
     */
    public function test_update_stage_accepts_method_override_via_post(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $url = route('marketing.campaigns.stage', $campaignId) . '?_method=PATCH';
        $response = $this->actingAs($this->user)->post($url, ['stage' => 'execucao']);

        $response->assertRedirect();
        $this->assertDatabaseHas('marketing_campaigns', ['id' => $campaignId, 'stage' => 'execucao']);
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

    /**
     * Incidente: excluir uma campanha a partir da sua própria tela de Show
     * caía em 404 — destroy() usava back(), que reaponta pra URL de origem
     * (o Show da campanha recém-apagada). Precisa redirecionar pro Kanban.
     */
    public function test_destroy_redirects_to_index_not_back_to_deleted_campaign(): void
    {
        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $this->companyId, 'name' => 'Campanha', 'stage' => 'ideia',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->from(route('marketing.campaigns.show', $campaignId))
            ->delete(route('marketing.campaigns.destroy', $campaignId));

        $response->assertRedirect(route('marketing.campaigns.index'));
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
