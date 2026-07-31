<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O cliente pediu explicitamente: o CPF precisa ser a chave que identifica o
 * mesmo comprador independente do canal (Mercado Livre, Shopee, Magazord,
 * Netshoes). Antes deste fix, Magazord/Netshoes nunca tocavam a tabela
 * `customers` nem preenchiam `orders.customer_id` — cada pedido ficava
 * "solto", só com o CPF cru dentro da própria linha.
 */
class CustomerIdentityLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function magazordVendasFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/magazord-vendas-customer-link-sample.csv'), 'vendas.csv', 'text/csv', null, true
        );
    }

    private function netshoesVendasFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/netshoes-vendas-sample.xlsx'), 'vendas.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );
    }

    public function test_magazord_import_links_same_cpf_in_different_formats_to_one_customer(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->magazordVendasFile(), 'create_missing' => true,
        ]);

        // 5001 (CPF "111.111.111-11") e 5002 (CPF "11111111111") são a mesma pessoa.
        $order1 = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 5001)->first();
        $order2 = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 5002)->first();

        $this->assertNotNull($order1->customer_id);
        $this->assertSame($order1->customer_id, $order2->customer_id);
        $this->assertSame(1, DB::table('customers')->where('company_id', $user->company_id)->where('doc_number', '11111111111')->count());
    }

    public function test_magazord_import_creates_separate_customer_for_different_cpf(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->magazordVendasFile(), 'create_missing' => true,
        ]);

        $order1 = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 5001)->first();
        $order3 = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 5003)->first();

        $this->assertNotSame($order1->customer_id, $order3->customer_id);
        $this->assertSame(2, DB::table('customers')->where('company_id', $user->company_id)->count());
    }

    public function test_magazord_reimport_does_not_duplicate_customer(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->magazordVendasFile(), 'create_missing' => true,
        ]);
        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->magazordVendasFile(), 'create_missing' => true,
        ]);

        $this->assertSame(2, DB::table('customers')->where('company_id', $user->company_id)->count());
    }

    public function test_same_cpf_across_magazord_and_netshoes_resolves_to_same_customer(): void
    {
        $user = $this->authenticatedUser();

        // Netshoes: pedido 1001, CPF 11111111111 (Cliente A).
        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), [
            'file' => $this->netshoesVendasFile(),
        ]);
        // Magazord: pedido 5001, CPF "111.111.111-11" -> mesmo CPF normalizado.
        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->magazordVendasFile(), 'create_missing' => true,
        ]);

        $netshoesOrder = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 1001)->first();
        $magazordOrder = DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 5001)->first();

        $this->assertNotNull($netshoesOrder->customer_id);
        $this->assertSame($netshoesOrder->customer_id, $magazordOrder->customer_id);
    }

    public function test_netshoes_import_sets_customer_id(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), [
            'file' => $this->netshoesVendasFile(),
        ]);

        // Pedido 1004 é "Troca" e é ignorado pelo importador antes de chegar
        // na vinculação de cliente — sobram 3 pedidos reais (1001, 1002, 1003).
        $orders = DB::table('orders')->where('company_id', $user->company_id)->get();
        $this->assertCount(3, $orders);
        foreach ($orders as $order) {
            $this->assertNotNull($order->customer_id, "pedido {$order->ml_order_id} deveria ter customer_id");
        }
        $this->assertSame(3, DB::table('customers')->where('company_id', $user->company_id)->count());
    }
}
