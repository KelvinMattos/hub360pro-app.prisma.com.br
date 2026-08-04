<?php

namespace Tests\Feature\Ads;

use App\Models\AdAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdAccountControllerTest extends TestCase
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

    public function test_index_requires_authentication(): void
    {
        $this->get(route('ads.accounts.index'))->assertRedirect(route('login'));
    }

    public function test_index_lista_contas_da_empresa(): void
    {
        AdAccount::create(['company_id' => $this->companyId, 'platform' => 'google_ads', 'label' => 'Conta Principal', 'is_active' => true]);
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        AdAccount::create(['company_id' => $outraEmpresa, 'platform' => 'google_ads', 'label' => 'Conta De Outra Empresa', 'is_active' => true]);

        $response = $this->actingAs($this->user)->get(route('ads.accounts.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Ads/Accounts')
            ->has('accounts', 1)
            ->where('accounts.0.label', 'Conta Principal')
        );
    }

    public function test_store_cria_conta(): void
    {
        $response = $this->actingAs($this->user)->post(route('ads.accounts.store'), [
            'platform' => 'meta_ads', 'label' => 'Conta Meta A', 'external_account_id' => 'act_123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('ad_accounts', [
            'company_id' => $this->companyId, 'platform' => 'meta_ads', 'label' => 'Conta Meta A', 'external_account_id' => 'act_123',
        ]);
    }

    public function test_store_rejeita_plataforma_invalida(): void
    {
        $response = $this->actingAs($this->user)->post(route('ads.accounts.store'), [
            'platform' => 'plataforma-fantasma', 'label' => 'X',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, AdAccount::count());
    }

    public function test_toggle_alterna_ativo_inativo(): void
    {
        $account = AdAccount::create(['company_id' => $this->companyId, 'platform' => 'google_ads', 'label' => 'Conta X', 'is_active' => true]);

        $this->actingAs($this->user)->patch(route('ads.accounts.toggle', $account->id))->assertRedirect();

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_toggle_bloqueia_conta_de_outra_empresa(): void
    {
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $account = AdAccount::create(['company_id' => $outraEmpresa, 'platform' => 'google_ads', 'label' => 'Conta Y', 'is_active' => true]);

        $this->actingAs($this->user)->patch(route('ads.accounts.toggle', $account->id))->assertNotFound();
    }

    public function test_destroy_remove_conta(): void
    {
        $account = AdAccount::create(['company_id' => $this->companyId, 'platform' => 'meta_ads', 'label' => 'Conta Z', 'is_active' => true]);

        $this->actingAs($this->user)->delete(route('ads.accounts.destroy', $account->id))->assertRedirect();

        $this->assertDatabaseMissing('ad_accounts', ['id' => $account->id]);
    }
}
