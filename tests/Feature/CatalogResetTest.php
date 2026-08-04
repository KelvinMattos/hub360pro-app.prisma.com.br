<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Zona de Perigo (Configurações do Sistema): antes apagava TUDO de uma vez
 * ("LIMPAR TUDO" apagava toda a lista fixa de tabelas). Pedido do cliente:
 * escolher com checkbox quais tabelas apagar, em vez de tudo-ou-nada.
 */
class CatalogResetTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function seedProductAndOrder(User $user): void
    {
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'Produto A']);
        DB::table('orders')->insertGetId([
            'company_id' => $user->company_id, 'ml_order_id' => '9001',
            'status' => 'approved', 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_apaga_apenas_as_tabelas_selecionadas(): void
    {
        $user = $this->authenticatedUser();
        $this->seedProductAndOrder($user);

        $response = $this->actingAs($user)->post(route('settings.system.reset_catalog'), [
            'confirm' => 'LIMPAR TUDO',
            'tables' => ['products'],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(1, DB::table('orders')->count()); // não selecionado -> preservado
    }

    public function test_sem_selecionar_nenhuma_tabela_retorna_erro_e_nada_e_apagado(): void
    {
        $user = $this->authenticatedUser();
        $this->seedProductAndOrder($user);

        $response = $this->actingAs($user)->post(route('settings.system.reset_catalog'), [
            'confirm' => 'LIMPAR TUDO',
            'tables' => [],
        ]);

        $response->assertSessionHasErrors('tables');
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_nome_de_tabela_fora_da_lista_conhecida_e_rejeitado(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->post(route('settings.system.reset_catalog'), [
            'confirm' => 'LIMPAR TUDO',
            'tables' => ['users'], // tabela sensível fora de CatalogResetService::$tables
        ]);

        $response->assertSessionHasErrors('tables');
        $this->assertSame(1, DB::table('users')->count());
    }

    public function test_frase_de_confirmacao_errada_nao_apaga_nada(): void
    {
        $user = $this->authenticatedUser();
        $this->seedProductAndOrder($user);

        $response = $this->actingAs($user)->post(route('settings.system.reset_catalog'), [
            'confirm' => 'apagar',
            'tables' => ['products', 'orders'],
        ]);

        $response->assertSessionHasErrors('confirm');
        $this->assertSame(1, DB::table('products')->count());
        $this->assertSame(1, DB::table('orders')->count());
    }

    public function test_selecionando_todas_as_tabelas_reproduz_o_limpar_tudo_de_antes(): void
    {
        $user = $this->authenticatedUser();
        $this->seedProductAndOrder($user);

        $allTables = collect((new \App\Services\CatalogResetService())->preview())->pluck('table')->all();

        $response = $this->actingAs($user)->post(route('settings.system.reset_catalog'), [
            'confirm' => 'LIMPAR TUDO',
            'tables' => $allTables,
        ]);

        $response->assertSessionHas('success');
        $this->assertSame(0, DB::table('products')->count());
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_requer_autenticacao(): void
    {
        $response = $this->post(route('settings.system.reset_catalog'), [
            'confirm' => 'LIMPAR TUDO',
            'tables' => ['products'],
        ]);
        $response->assertRedirect(route('login'));
    }
}
