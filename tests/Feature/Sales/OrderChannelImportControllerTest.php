<?php

namespace Tests\Feature\Sales;

use App\Models\SalesChannelAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Importadores nativos de Vendas por canal (Mercado Livre, Shopee, Centauro,
 * Renner, Magazine Luiza) — cada fixture reproduz o layout real validado
 * durante o desenvolvimento contra os arquivos enviados pelo cliente.
 * Cobre também o suporte a múltiplas contas por canal (pedido do cliente
 * 03/08/2026).
 */
class OrderChannelImportControllerTest extends TestCase
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

    private function account(string $channel): SalesChannelAccount
    {
        return SalesChannelAccount::create([
            'company_id' => $this->companyId, 'channel' => $channel, 'label' => 'Conta Teste', 'is_active' => true,
        ]);
    }

    public function test_show_requires_authentication(): void
    {
        $this->get(route('order-channel.show', ['type' => 'mercado_livre']))->assertRedirect(route('login'));
    }

    public function test_show_404_para_tipo_desconhecido(): void
    {
        $this->actingAs($this->user)->get(route('order-channel.show', ['type' => 'inexistente']))->assertNotFound();
    }

    public function test_show_lista_só_as_contas_ativas_do_proprio_canal(): void
    {
        $this->account('mercado_livre');
        SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'mercado_livre', 'label' => 'Inativa', 'is_active' => false]);
        SalesChannelAccount::create(['company_id' => $this->companyId, 'channel' => 'shopee', 'label' => 'Outra Shopee', 'is_active' => true]);

        $response = $this->actingAs($this->user)->get(route('order-channel.show', ['type' => 'mercado_livre']));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('SalesChannel/OrderImport')
            ->has('accounts', 1)
            ->where('accounts.0.label', 'Conta Teste')
        );
    }

    public function test_import_exige_conta_valida(): void
    {
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-mercado-livre-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $response = $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'mercado_livre']), [
            'file' => $file, 'account_id' => 99999,
        ]);

        $response->assertRedirect(route('order-channel.show', ['type' => 'mercado_livre']));
        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_import_rejeita_conta_de_outro_canal(): void
    {
        $shopeeAccount = $this->account('shopee');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-mercado-livre-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $response = $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'mercado_livre']), [
            'file' => $file, 'account_id' => $shopeeAccount->id,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('orders')->count());
    }

    public function test_import_mercado_livre_cria_pedidos_e_itens(): void
    {
        $account = $this->account('mercado_livre');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-mercado-livre-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $response = $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'mercado_livre']), [
            'file' => $file, 'account_id' => $account->id,
        ]);

        $response->assertSessionHas('success');
        $this->assertDatabaseHas('orders', [
            'company_id' => $this->companyId, 'ml_order_id' => '2000000000001',
            'selling_channel' => 'Mercado Livre', 'sales_channel_account_id' => $account->id,
            'status' => 'delivered', 'total_amount' => 71.7, 'marketplace_fee' => 12.55, 'shipping_cost' => 13.84,
        ]);
        // Pedido "split" (troca/devolução, 2 linhas) — mesclado numa única orders.
        $this->assertDatabaseHas('orders', [
            'ml_order_id' => '2000000000002', 'status' => 'cancelled', 'customer_name' => 'Comprador ML Dois',
        ]);
        $this->assertSame(2, DB::table('orders')->where('company_id', $this->companyId)->count());
    }

    public function test_import_shopee_soma_itens_sem_duplicar_total_do_pedido(): void
    {
        $account = $this->account('shopee');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-shopee-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'shopee']), [
            'file' => $file, 'account_id' => $account->id,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', [
            'ml_order_id' => 'SP0001', 'selling_channel' => 'Shopee', 'total_amount' => 71.9,
            'marketplace_fee' => 24.5, 'shipping_cost' => 18.11, 'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('order_items', ['sku' => '9001-VAR', 'quantity' => 1, 'unit_price' => 71.9]);
    }

    public function test_import_centauro_agrupa_multiplos_itens_do_mesmo_pedido(): void
    {
        $account = $this->account('centauro');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-centauro-sample.csv'), 'vendas.csv', null, null, true);

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'centauro']), [
            'file' => $file, 'account_id' => $account->id,
        ])->assertSessionHas('success');

        $order = DB::table('orders')->where('ml_order_id', 'CT0002')->first();
        $this->assertNotNull($order);
        $this->assertSame(2, DB::table('order_items')->where('order_id', $order->id)->count());
        $this->assertSame(10.0, (float) $order->discount_amount); // desconto repetido nas 2 linhas — não pode dobrar
    }

    public function test_import_renner_nao_gera_order_items(): void
    {
        $account = $this->account('renner');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-renner-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'renner']), [
            'file' => $file, 'account_id' => $account->id,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['ml_order_id' => 'RN-0001', 'selling_channel' => 'Renner', 'total_amount' => 195.89]);
        $this->assertSame(0, DB::table('order_items')->count());
    }

    public function test_import_magalu_captura_taxa_do_marketplace(): void
    {
        $account = $this->account('magalu');
        $file = new UploadedFile(base_path('tests/Fixtures/order-channel-magalu-sample.csv'), 'vendas.csv', null, null, true);

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'magalu']), [
            'file' => $file, 'account_id' => $account->id,
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('orders', [
            'ml_order_id' => 'LU-0001', 'selling_channel' => 'Magazine Luiza', 'total_amount' => 19.89,
            'marketplace_fee' => 3.58, 'status' => 'cancelled',
        ]);
    }

    public function test_import_e_idempotente_ao_reimportar(): void
    {
        $account = $this->account('mercado_livre');
        $file = fn () => new UploadedFile(base_path('tests/Fixtures/order-channel-mercado-livre-sample.xlsx'), 'vendas.xlsx', null, null, true);

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'mercado_livre']), [
            'file' => $file(), 'account_id' => $account->id,
        ]);
        $countAfterFirst = DB::table('orders')->count();

        $this->actingAs($this->user)->post(route('order-channel.import', ['type' => 'mercado_livre']), [
            'file' => $file(), 'account_id' => $account->id,
        ]);
        $countAfterSecond = DB::table('orders')->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_progress_sem_token_conhecido_retorna_pending(): void
    {
        $response = $this->actingAs($this->user)->get(route('order-channel.progress', 'token-inexistente'));

        $response->assertOk();
        $response->assertJson(['status' => 'pending', 'done' => 0, 'total' => 0]);
    }
}
