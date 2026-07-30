<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Importação de Vendas Netshoes: export de Pedidos do Seller Center vem por
 * ITEM do pedido (aba "pedidos_por_item") — este importador agrupa por
 * "Número Pedido" antes de gravar em `orders`. Pedidos "Troca" são
 * ignorados. Fixture sintética replica exatamente a estrutura real
 * (validada manualmente contra um export real de 580 linhas/557 pedidos).
 */
class NetshoesImportVendasTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function vendasFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/netshoes-vendas-sample.xlsx'), 'vendas.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true
        );
    }

    public function test_import_deduplicates_multi_item_order_into_one_row(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);

        $response->assertRedirect(route('netshoes.show', ['type' => 'vendas']));
        $response->assertSessionHas('success');

        // Pedido 1001 tem 2 linhas de item na fixture -> só 1 registro em orders.
        $this->assertSame(1, DB::table('orders')->where('company_id', $user->company_id)->where('ml_order_id', 1001)->count());
    }

    public function test_import_ignores_troca_orders(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);

        $this->assertDatabaseMissing('orders', ['company_id' => $user->company_id, 'ml_order_id' => 1004]);
    }

    public function test_import_maps_status_correctly(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);

        $this->assertDatabaseHas('orders', ['ml_order_id' => 1001, 'status' => 'approved']); // Faturado
        $this->assertDatabaseHas('orders', ['ml_order_id' => 1002, 'status' => 'delivered']); // Entregue
        $this->assertDatabaseHas('orders', ['ml_order_id' => 1003, 'status' => 'cancelled']); // Cancelado
    }

    public function test_import_parses_date_and_total_correctly(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);

        $order = DB::table('orders')->where('ml_order_id', 1001)->first();
        $this->assertSame('2026-06-29 16:54:36', $order->date_created);
        $this->assertSame('459.90', $order->total_amount);
        $this->assertSame('459.90', $order->total_paid_amount);
        $this->assertSame('Cliente A', $order->buyer_nickname);
    }

    public function test_import_is_idempotent_on_reimport(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);
        $countAfterFirst = DB::table('orders')->where('company_id', $user->company_id)->count();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);
        $countAfterSecond = DB::table('orders')->where('company_id', $user->company_id)->count();

        $this->assertSame($countAfterFirst, $countAfterSecond);
        $this->assertSame(3, $countAfterSecond); // 1001, 1002, 1003 (1004 é Troca)
    }

    public function test_import_writes_company_id_on_created_orders(): void
    {
        $user = $this->authenticatedUser();

        $this->actingAs($user)->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);

        $this->assertDatabaseHas('orders', ['ml_order_id' => 1001, 'company_id' => $user->company_id]);
    }

    /**
     * ACHADO (não corrigido — fora do escopo pedido): orders.ml_order_id tem
     * índice único GLOBAL, não composto com company_id. Na prática isso não
     * afeta Netshoes (o "Número Pedido" é exclusivo por venda real, nunca se
     * repete entre empresas de verdade), mas é a mesma limitação que já
     * existe em MagazordImportController::importVendas(). Registrado aqui
     * como nota, não testado como cenário — duas empresas com o MESMO número
     * de pedido não é um caso real, só ocorreria com dado sintético de teste.
     */

    public function test_import_requires_authentication(): void
    {
        $response = $this->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $this->vendasFile()]);
        $response->assertRedirect(route('login'));
    }

    /**
     * A leitura/agrupamento por "Número Pedido" (antes da transação de escrita)
     * não estava dentro de um try/catch — um arquivo corrompido derrubaria a
     * requisição inteira com 500 e o progresso nunca seria marcado "done": o
     * polling do frontend ficaria girando pra sempre sem nunca mostrar o
     * relatório de erro. Corrigido para sempre retornar um resultado (ok:false).
     */
    public function test_import_reports_failure_instead_of_crashing_on_corrupt_file(): void
    {
        $user = $this->authenticatedUser();

        $corrupt = UploadedFile::fake()->createWithContent('vendas.xlsx', 'isso não é um xlsx de verdade');

        $response = $this->actingAs($user)
            ->post(route('netshoes.import', ['type' => 'vendas']), ['file' => $corrupt]);

        $response->assertRedirect(route('netshoes.show', ['type' => 'vendas']));
        $response->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }
}
