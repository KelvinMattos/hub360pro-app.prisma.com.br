<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Achado ao revisar se os importadores de Vendas do Magazord aproveitam tudo
 * que a planilha real traz (pedido do cliente 03/08/2026): o modelo "Vendas"
 * (Consulta de Pedidos, ver IMPORTAR_VENDAS.csv) tem as colunas "Valor
 * Desconto" e "Valor Acréscimo" (juros de parcelamento) — eram descartadas
 * silenciosamente porque `orders` não tinha onde gravá-las. Validado contra
 * o arquivo real: pedido 255945 tinha desconto=19,99 e pedido 255941 tinha
 * acréscimo=2,06 — os mesmos valores usados aqui.
 */
class MagazordImportVendasDiscountSurchargeTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);

        return User::factory()->create(['company_id' => $companyId]);
    }

    private function vendasFile(): UploadedFile
    {
        $header = "Pedido Id;Código;Data/Hora;Cliente;CPF/CNPJ;Situação;Marketplace;Forma de Pagamento;Valor Desconto;Valor Acréscimo;Valor Total Pedido\n";
        $rows = "255945;255945;01/07/2026 10:00:00;Cliente Um;111.111.111-11;Entregue;Mercado Livre;Cartão;19,99;0,00;100,00\n"
            . "255941;255941;01/07/2026 11:00:00;Cliente Dois;222.222.222-22;Entregue;Mercado Livre;Cartão;0,00;2,06;50,00\n";

        return UploadedFile::fake()->createWithContent('vendas.csv', mb_convert_encoding($header . $rows, 'ISO-8859-1', 'UTF-8'));
    }

    public function test_import_captures_discount_and_surcharge_from_orders_export(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->post(route('magazord.import', ['type' => 'vendas']), [
            'file' => $this->vendasFile(), 'create_missing' => true,
        ]);

        $response->assertRedirect(route('magazord.show', ['type' => 'vendas']));
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('orders', ['ml_order_id' => 255945, 'discount_amount' => 19.99, 'surcharge_amount' => 0]);
        $this->assertDatabaseHas('orders', ['ml_order_id' => 255941, 'discount_amount' => 0, 'surcharge_amount' => 2.06]);
    }
}
