<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Consulta Dinâmica – Detalhes do Pedido" (FADERIM → Consultas Dinâmicas):
 * a única fonte de Vendas que traz cidade/estado do comprador — os outros
 * importadores de Vendas (Magazord "vendas"/"vendas_itens", Netshoes) nunca
 * capturam essa informação, então o relatório de Vendas por Região da
 * Central de Vendas ficava sempre vazio pra pedidos dessas origens.
 *
 * Fixture validada contra uma amostra real do relatório (mesmas colunas e
 * formatos: CPF com máscara, valores BR com 4 casas decimais, UF de 2 letras).
 */
class MagazordImportVendasDetalhesTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function detalhesFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/magazord-vendas-detalhes-sample.csv'), 'detalhes.csv', 'text/csv', null, true
        );
    }

    private function import(User $user, bool $createMissing = true)
    {
        return $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas_detalhes']), [
            'file' => $this->detalhesFile(),
            'create_missing' => $createMissing,
        ]);
    }

    public function test_import_creates_orders_with_channel_status_and_total(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->import($user);

        $response->assertRedirect(route('magazord.show', ['type' => 'vendas_detalhes']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('orders', [
            'company_id' => $user->company_id, 'ml_order_id' => 6001,
            'selling_channel' => 'Mercado Livre', 'status' => 'delivered', 'total_amount' => 170,
        ]);
        $this->assertDatabaseHas('orders', [
            'company_id' => $user->company_id, 'ml_order_id' => 6002,
            'selling_channel' => 'Centauro', 'status' => 'cancelled', 'total_amount' => 70,
        ]);
    }

    public function test_import_parses_date_correctly(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);

        $order = DB::table('orders')->where('ml_order_id', 6001)->first();
        $this->assertSame('2026-07-05 00:00:00', $order->date_created);
    }

    /** O dado que este importador existe pra trazer: cidade/estado, gravado no cliente (não no pedido). */
    public function test_import_populates_customer_city_and_state(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);

        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id, 'doc_number' => '11111111111', 'city' => 'São Paulo', 'state' => 'SP',
        ]);
        $this->assertDatabaseHas('customers', [
            'company_id' => $user->company_id, 'doc_number' => '22222222222', 'city' => 'Salvador', 'state' => 'BA',
        ]);
    }

    public function test_import_links_same_cpf_in_different_formats_to_one_customer_and_sets_customer_id(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);

        // 6001 (CPF "111.111.111-11") e 6003 (CPF "11111111111") são a mesma pessoa.
        $order1 = DB::table('orders')->where('ml_order_id', 6001)->first();
        $order3 = DB::table('orders')->where('ml_order_id', 6003)->first();

        $this->assertNotNull($order1->customer_id);
        $this->assertSame($order1->customer_id, $order3->customer_id);
        $this->assertSame(2, DB::table('customers')->where('company_id', $user->company_id)->count());
    }

    public function test_import_is_idempotent_on_reimport(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);
        $countAfterFirst = DB::table('orders')->where('company_id', $user->company_id)->count();
        $customersAfterFirst = DB::table('customers')->where('company_id', $user->company_id)->count();

        $this->import($user);
        $countAfterSecond = DB::table('orders')->where('company_id', $user->company_id)->count();
        $customersAfterSecond = DB::table('customers')->where('company_id', $user->company_id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertSame($customersAfterFirst, $customersAfterSecond);
        $this->assertSame(3, $countAfterSecond);
    }

    public function test_import_writes_company_id_on_created_orders(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);

        $this->assertDatabaseHas('orders', ['ml_order_id' => 6001, 'company_id' => $user->company_id]);
    }

    /** O motivo deste importador existir: alimenta o relatório de Vendas por Região, que antes ficava sempre vazio. */
    public function test_import_feeds_sales_region_report(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);
        // Pedidos "vendas_detalhes" só são faturados se o status mapear pra
        // confirmado — "Entregue" mapeia pra "delivered", que está em
        // Order::CONFIRMED_STATUSES.
        DB::table('orders')->update(['date_created' => now()]);

        $service = app(\App\Services\Sales\SalesAnalyticsService::class);
        $porEstado = $service->porRegiaoEstado($user->company_id, 30);

        // Só SP aparece: os dois pedidos "Entregue" (6001, 6003) são de São
        // Paulo; o único pedido da Bahia (6002) está "Cancelado Pagamento" —
        // corretamente fora do relatório de vendas faturadas.
        $estados = collect($porEstado)->pluck('estado');
        $this->assertTrue($estados->contains('SP'));
        $this->assertFalse($estados->contains('BA'));
    }

    /**
     * Achado ao revisar se os importadores de Vendas do Magazord aproveitam
     * tudo que a planilha real traz (pedido do cliente 03/08/2026): "Vlr
     * Desconto"/"Vlr Acréscimo" existiam no arquivo e eram descartados —
     * orders não tinha coluna pra isso. O pedido 6002 desta fixture tem
     * Vlr Desconto=10,0000 real.
     */
    public function test_import_captures_discount_amount(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user);

        $this->assertDatabaseHas('orders', ['ml_order_id' => 6002, 'discount_amount' => 10]);
        $this->assertDatabaseHas('orders', ['ml_order_id' => 6001, 'discount_amount' => 0, 'surcharge_amount' => 0]);
    }

    public function test_import_requires_authentication(): void
    {
        $response = $this->post(route('magazord.import', ['type' => 'vendas_detalhes']), [
            'file' => $this->detalhesFile(), 'create_missing' => true,
        ]);
        $response->assertRedirect(route('login'));
    }
}
