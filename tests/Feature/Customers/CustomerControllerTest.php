<?php

namespace Tests\Feature\Customers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A tela "Clientes" agrupa `orders` por CPF em tempo real (não usa a tabela
 * `customers`), pra funcionar sobre o histórico já importado mesmo antes do
 * CustomerIdentityService existir. Precisa normalizar o CPF (tira pontuação)
 * e ler qualquer que seja a coluna onde o canal de origem gravou
 * (billing_doc_number ou customer_doc), senão o mesmo cliente aparece
 * picotado em várias linhas.
 */
class CustomerControllerTest extends TestCase
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

    private function insertOrder(array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'company_id' => $this->companyId, 'status' => 'paid', 'total_amount' => 100,
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    public function test_index_merges_same_cpf_across_different_column_and_format(): void
    {
        // Mercado Livre grava em billing_doc_number, com máscara.
        $this->insertOrder(['billing_doc_number' => '111.111.111-11', 'buyer_nickname' => 'Cliente A', 'total_amount' => 100]);
        // Magazord grava em customer_doc, sem máscara.
        $this->insertOrder(['customer_doc' => '11111111111', 'buyer_nickname' => 'Cliente A', 'total_amount' => 50]);

        $response = $this->actingAs($this->user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.billing_doc_number', '11111111111')
            ->where('customers.data.0.total_orders', 2)
            ->where('customers.data.0.total_spent', '150.00')
        );
    }

    public function test_index_keeps_different_cpf_as_separate_rows(): void
    {
        $this->insertOrder(['customer_doc' => '11111111111', 'buyer_nickname' => 'Cliente A']);
        $this->insertOrder(['customer_doc' => '22222222222', 'buyer_nickname' => 'Cliente B']);

        $response = $this->actingAs($this->user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('customers.data', 2));
    }

    public function test_index_ignores_orders_without_any_doc(): void
    {
        $this->insertOrder(['customer_doc' => null, 'billing_doc_number' => null]);

        $response = $this->actingAs($this->user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    }

    public function test_index_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('orders')->insert([
            'company_id' => $otherCompanyId, 'status' => 'paid', 'total_amount' => 999,
            'customer_doc' => '99999999999', 'buyer_nickname' => 'De outra empresa',
            'date_created' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('customers.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page->has('customers.data', 0));
    }

    public function test_show_finds_customer_by_normalized_doc_from_url(): void
    {
        $this->insertOrder(['customer_doc' => '111.111.111-11', 'buyer_nickname' => 'Cliente A', 'total_amount' => 200]);

        $response = $this->actingAs($this->user)->get(route('customers.show', '11111111111'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('customer.billing_doc_number', '11111111111')
            ->where('stats.total_spent', 200)
        );
    }

    public function test_show_redirects_with_error_when_customer_not_found(): void
    {
        $response = $this->actingAs($this->user)->get(route('customers.show', '00000000000'));

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHas('error');
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('customers.index'));
        $response->assertRedirect(route('login'));
    }
}
