<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Consulta Dinâmica – Produto por Pedido" (FADERIM → Consultas Dinâmicas):
 * a única fonte de Vendas que traz SKU + quantidade + valor unitário por
 * linha. Os outros dois importadores de Vendas (Magazord "vendas" e Netshoes
 * "vendas") só gravam cabeçalho de pedido — order_items nunca era escrita, e
 * o motor de Reposição Inteligente via velocity=0 em 100% do catálogo.
 *
 * Fixture validada contra um export real (25.392 linhas / 21.924 pedidos
 * únicos, colunas idênticas às daqui) via tinker antes destes testes.
 */
class MagazordImportVendasItensTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function vendasItensFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/magazord-vendas-itens-sample.csv'), 'vendas_itens.csv', 'text/csv', null, true
        );
    }

    private function import(User $user, bool $createMissing = true)
    {
        return $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas_itens']), [
            'file' => $this->vendasItensFile(),
            'create_missing' => $createMissing,
        ]);
    }

    public function test_import_writes_order_items_with_real_quantity_and_price(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A', 'cost_price' => 40]);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B', 'cost_price' => 15]);

        $response = $this->import($user);

        $response->assertRedirect(route('magazord.show', ['type' => 'vendas_itens']));
        $response->assertSessionHas('success');

        $order = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', '2001')->first();
        $this->assertNotNull($order);

        $item = DB::table('order_items')->where('order_id', $order->id)->where('sku', 'SKU-A')->first();
        $this->assertNotNull($item);
        $this->assertSame(1, (int) $item->quantity);
        $this->assertSame('100.00', $item->unit_price);
        $this->assertSame('40.00', $item->unit_cost); // custo real do produto, não a estimativa
    }

    public function test_import_creates_one_item_row_per_sku_in_multi_item_order(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->import($user);

        $order = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', '2002')->first();
        $this->assertSame(2, DB::table('order_items')->where('order_id', $order->id)->count());
    }

    public function test_import_sums_quantity_when_same_sku_repeats_in_same_order(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);

        $this->import($user);

        // Pedido 2003 tem SKU-A duas vezes (1 unidade cada linha) -> soma pra 2, não duplica a linha.
        $order = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', '2003')->first();
        $items = DB::table('order_items')->where('order_id', $order->id)->where('sku', 'SKU-A')->get();
        $this->assertCount(1, $items);
        $this->assertSame(2, (int) $items->first()->quantity);
    }

    /**
     * Bug real corrigido em mapStatus(): "Transporte" não batia em nenhum
     * str_contains e caía em "pending" (fora de Order::CONFIRMED_STATUSES).
     * No export real era 27% de todas as linhas — a maior fatia de qualquer status.
     */
    public function test_transporte_status_maps_to_shipped_not_pending(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->import($user);

        $this->assertDatabaseHas('orders', ['ml_order_id' => '2002', 'status' => 'shipped']);
    }

    public function test_import_maps_other_statuses_correctly(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->import($user);

        $this->assertDatabaseHas('orders', ['ml_order_id' => '2001', 'status' => 'approved']); // Nota Fiscal Emitida
        $this->assertDatabaseHas('orders', ['ml_order_id' => '2003', 'status' => 'delivered']); // Entregue
        $this->assertDatabaseHas('orders', ['ml_order_id' => '2004', 'status' => 'cancelled']); // Cancelado Pagamento
    }

    public function test_sku_not_in_catalog_is_reported_but_item_still_written(): void
    {
        $user = $this->authenticatedUser();
        // Nenhum produto cadastrado -> SKU-DESCONHECIDO (pedido 2004) fica sem match.

        $response = $this->import($user);

        $order = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', '2004')->first();
        $item = DB::table('order_items')->where('order_id', $order->id)->first();
        $this->assertNull($item->product_id);
        $this->assertSame('SKU-DESCONHECIDO', $item->sku);

        $response->assertSessionHas('success');
        $this->assertStringContainsString('SKUs não encontrados', session('success'));
    }

    public function test_reimport_is_idempotent_and_updates_existing_order_and_item(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'B']);

        $this->import($user);
        $ordersAfterFirst = DB::table('orders')->where('company_id', $user->company_id)->count();
        $itemsAfterFirst = DB::table('order_items')->count();

        $this->import($user);
        $ordersAfterSecond = DB::table('orders')->where('company_id', $user->company_id)->count();
        $itemsAfterSecond = DB::table('order_items')->count();

        $this->assertSame($ordersAfterFirst, $ordersAfterSecond);
        $this->assertSame($itemsAfterFirst, $itemsAfterSecond);
    }

    public function test_existing_order_from_header_only_import_gets_items_filled_in(): void
    {
        // Simula o cenário real do backfill: o pedido já existe (criado pelo
        // importador "vendas" de cabeçalho), só falta preencher os itens.
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'A']);

        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $user->company_id, 'ml_order_id' => '2001',
            'status' => 'approved', 'total_amount' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->import($user);

        $this->assertSame(1, DB::table('orders')->where('id', $orderId)->count());
        $this->assertSame(1, DB::table('order_items')->where('order_id', $orderId)->count());
    }

    public function test_import_requires_authentication(): void
    {
        $response = $this->post(route('magazord.import', ['type' => 'vendas_itens']), ['file' => $this->vendasItensFile()]);
        $response->assertRedirect(route('login'));
    }
}
