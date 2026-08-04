<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pedido do cliente (04/08/2026): as colunas "Origem - Source", "Origem -
 * Medium", "Origem - Referência (site)", "Origem - Campaign" e "Origem -
 * Dispositivo" existem na "Consulta de Pedidos" do Magazord (mesma fonte do
 * importador "Vendas", validado contra
 * Exportar_Consulta_de_Pedidos20260804_08_14_45.csv real) e eram
 * descartadas — é a origem real da venda (UTM), usada pelo Dashboard de ADS
 * pra cruzar com gasto de campanha.
 */
class MagazordImportVendasUtmTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['company_id' => $companyId]);
    }

    private function vendasFile(): UploadedFile
    {
        $header = "Pedido Id;Código;Data/Hora;Cliente;CPF/CNPJ;Situação;Marketplace;Forma de Pagamento;Valor Desconto;Valor Acréscimo;Valor Total Pedido;Origem - Source;Origem - Medium;Origem - Referência (site);Origem - Campaign;Origem - Dispositivo\n";
        $rows = "256882;256882;04/08/2026 07:51:49;Pedro Vianna;071.918.343-07;Cancelado Pagamento;;Cartão;0,00;0,00;728,69;google;Shopping;httpswwwgooglecom;tenis-nike-initiator-masculino-hq1179;Desktop\n"
            . "256873;256873;04/08/2026 06:10:00;Outro Cliente;222.222.222-22;Entregue;;Cartão;0,00;0,00;199,90;ig;social;httpslinstagramcom;;Mobile\n"
            . "256844;256844;04/08/2026 05:00:00;Terceiro Cliente;333.333.333-33;Entregue;;Cartão;0,00;0,00;99,90;;;;;Aplicativo\n";

        return UploadedFile::fake()->createWithContent('vendas.csv', mb_convert_encoding($header . $rows, 'ISO-8859-1', 'UTF-8'));
    }

    public function test_import_captures_utm_fields_from_orders_export(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->vendasFile(), 'create_missing' => true,
        ]);

        $response->assertSessionHas('success');

        $this->assertDatabaseHas('orders', [
            'ml_order_id' => 256882, 'utm_source' => 'google', 'utm_medium' => 'Shopping',
            'utm_campaign' => 'tenis-nike-initiator-masculino-hq1179', 'utm_referrer' => 'httpswwwgooglecom', 'utm_device' => 'Desktop',
        ]);
        $this->assertDatabaseHas('orders', [
            'ml_order_id' => 256873, 'utm_source' => 'ig', 'utm_medium' => 'social', 'utm_device' => 'Mobile',
        ]);
    }

    /** Pedido sem UTM (ex.: venda pelo app) não deve gravar string vazia — fica null. */
    public function test_import_leaves_utm_null_when_absent(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->vendasFile(), 'create_missing' => true,
        ]);

        $order = DB::table('orders')->where('ml_order_id', 256844)->first();
        $this->assertNull($order->utm_source);
        $this->assertNull($order->utm_campaign);
        $this->assertSame('Aplicativo', $order->utm_device);
    }
}
