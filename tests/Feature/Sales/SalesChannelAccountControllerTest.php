<?php

namespace Tests\Feature\Sales;

use App\Models\SalesChannelAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesChannelAccountControllerTest extends TestCase
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
        $this->get(route('sales.channel-accounts.index'))->assertRedirect(route('login'));
    }

    public function test_index_lista_contas_da_empresa(): void
    {
        SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'mercado_livre', 'label' => 'Loja A', 'is_active' => true]);
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        SalesChannelAccount::create(['company_id' => $outraEmpresa, 'channel' => 'mercado_livre', 'label' => 'Loja De Outra Empresa', 'is_active' => true]);

        $response = $this->actingAs($this->user)->get(route('sales.channel-accounts.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SalesChannel/Accounts')
            ->has('accounts', 1)
            ->where('accounts.0.label', 'Loja A')
        );
    }

    public function test_store_cria_conta(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.channel-accounts.store'), [
            'channel' => 'shopee', 'label' => 'Loja Shopee A', 'external_identifier' => 'seller123',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('sales_channel_accounts', [
            'company_id' => $this->companyId, 'channel' => 'shopee', 'label' => 'Loja Shopee A', 'external_identifier' => 'seller123',
        ]);
    }

    public function test_store_rejeita_canal_invalido(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.channel-accounts.store'), [
            'channel' => 'canal-fantasma', 'label' => 'X',
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, SalesChannelAccount::count());
    }

    public function test_toggle_alterna_ativo_inativo(): void
    {
        $account = SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'centauro', 'label' => 'Loja X', 'is_active' => true]);

        $this->actingAs($this->user)->patch(route('sales.channel-accounts.toggle', $account->id))->assertRedirect();

        $this->assertFalse($account->fresh()->is_active);
    }

    public function test_toggle_bloqueia_conta_de_outra_empresa(): void
    {
        $outraEmpresa = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $account = SalesChannelAccount::create(['company_id' => $outraEmpresa, 'channel' => 'centauro', 'label' => 'Loja Y', 'is_active' => true]);

        $this->actingAs($this->user)->patch(route('sales.channel-accounts.toggle', $account->id))->assertNotFound();
    }

    public function test_destroy_remove_conta(): void
    {
        $account = SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'renner', 'label' => 'Loja Z', 'is_active' => true]);

        $this->actingAs($this->user)->delete(route('sales.channel-accounts.destroy', $account->id))->assertRedirect();

        $this->assertDatabaseMissing('sales_channel_accounts', ['id' => $account->id]);
    }
}
