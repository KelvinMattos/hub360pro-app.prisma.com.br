<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * As 5 rotas pedidas (/orders, /expedition, /marketplaces/questions,
 * /marketplaces/auto-reply, /marketplaces/listings/bulk) nascem DESLIGADAS
 * (config/features.php, todas false por padrão) — 404 sem apagar nenhum
 * dado ou tabela. Ligando a flag correspondente, a rota volta a responder
 * normalmente (a trava é só o middleware, nunca a camada de dados).
 */
class FeatureFlagRoutesTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    public function test_orders_index_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('orders.index'))->assertNotFound();
    }

    public function test_orders_index_responds_when_flag_enabled(): void
    {
        config(['features.orders' => true]);
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('orders.index'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_orders_show_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('orders.show', ['id' => 1]))->assertNotFound();
    }

    public function test_expedition_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('orders.expedition'))->assertNotFound();
    }

    public function test_expedition_responds_when_flag_enabled(): void
    {
        config(['features.expedition' => true]);
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('orders.expedition'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_marketplace_questions_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('marketplaces.questions.index'))->assertNotFound();
    }

    public function test_marketplace_questions_responds_when_flag_enabled(): void
    {
        config(['features.marketplaces_questions' => true]);
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('marketplaces.questions.index'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_marketplace_auto_reply_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('marketplaces.auto-reply.index'))->assertNotFound();
    }

    public function test_marketplace_auto_reply_responds_when_flag_enabled(): void
    {
        config(['features.marketplaces_auto_reply' => true]);
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('marketplaces.auto-reply.index'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_marketplace_listings_bulk_is_404_by_default(): void
    {
        $user = $this->authenticatedUser();
        $this->actingAs($user)->get(route('marketplaces.listings.bulk'))->assertNotFound();
    }

    public function test_marketplace_listings_bulk_responds_when_flag_enabled(): void
    {
        config(['features.marketplaces_listings_bulk' => true]);
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('marketplaces.listings.bulk'));
        $this->assertNotSame(404, $response->status());
    }

    /** listings.index NÃO está atrás de flag — só a edição em massa foi pedida. */
    public function test_marketplace_listings_index_stays_available_regardless_of_bulk_flag(): void
    {
        $user = $this->authenticatedUser();
        $response = $this->actingAs($user)->get(route('marketplaces.listings.index'));
        $this->assertNotSame(404, $response->status());
    }

    public function test_flags_do_not_drop_any_table_or_data(): void
    {
        // As tabelas continuam existindo e utilizáveis — a trava é só de rota.
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('orders'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('products'));
    }
}
