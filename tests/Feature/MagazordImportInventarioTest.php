<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * "Inventário Geral": contagem física em .xlsx (COD/NCM/Descrição/TAM/
 * Quantidade/Unid/Custo Unr/Custo R$). Bug real reportado pelo cliente: o
 * custo unitário certo é CUSTO UNR — CUSTO R$ é CUSTO UNR × Quantidade da
 * linha (confirmado na amostra real: mesmo COD repetido uma vez por
 * tamanho, CUSTO UNR constante entre as linhas do mesmo COD, CUSTO R$
 * variando conforme a quantidade daquele tamanho).
 *
 * Fixture (tests/Fixtures/magazord-inventario-sample.xlsx):
 *   SKU-A / TAM 39 / qtd 2 / custo unr 390,66 / custo r$  781,32
 *   SKU-A / TAM 40 / qtd 3 / custo unr 390,66 / custo r$ 1171,98
 *   SKU-B / UNICO  / qtd 1 / custo unr  50,50 / custo r$   50,50
 *   SKU-C / UNICO  / qtd 0 / custo unr  30,00 / custo r$    0,00
 *   SKU-D / UNICO  / (sem quantidade nem custo)
 *
 * Custo com centavos (390,66) de propósito: o openspout devolve célula
 * numérica como float nativo do PHP (ponto decimal), e brNumber() espera
 * formato BR (vírgula decimal) — sem a conversão em xlsxCellToString(),
 * esse valor viraria 39066 (o ponto sendo removido como se fosse separador
 * de milhar).
 */
class MagazordImportInventarioTest extends TestCase
{
    use RefreshDatabase;

    private function authenticatedUser(): User
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        return User::factory()->create(['company_id' => $companyId]);
    }

    private function inventarioFile(): UploadedFile
    {
        return new UploadedFile(
            base_path('tests/Fixtures/magazord-inventario-sample.xlsx'),
            'inventario.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function import(User $user, bool $createMissing = false)
    {
        return $this->actingAs($user)->post(route('magazord.import', ['type' => 'inventario']), [
            'file' => $this->inventarioFile(),
            'create_missing' => $createMissing,
        ]);
    }

    public function test_usa_custo_unr_e_nao_custo_total_e_soma_estoque_por_tamanho(): void
    {
        $user = $this->authenticatedUser();
        $product = Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'Produto A', 'cost_price' => 1, 'stock_quantity' => 0]);

        $response = $this->import($user);
        $response->assertRedirect(route('magazord.show', ['type' => 'inventario']));
        $response->assertSessionHas('success');

        $product->refresh();
        // Custo = CUSTO UNR (390,66), nunca CUSTO R$ (781,32/1171,98) nem sua soma (1953,30).
        $this->assertSame('390.66', $product->cost_price);
        // Estoque = soma da Quantidade das duas linhas do mesmo COD (2 + 3).
        $this->assertSame(5, (int) $product->stock_quantity);
    }

    public function test_produto_existente_com_uma_unica_linha_e_atualizado(): void
    {
        $user = $this->authenticatedUser();
        $product = Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-B', 'title' => 'Produto B', 'cost_price' => 1, 'stock_quantity' => 0]);

        $this->import($user);

        $product->refresh();
        $this->assertSame('50.50', $product->cost_price);
        $this->assertSame(1, (int) $product->stock_quantity);
    }

    public function test_create_missing_cria_produto_novo_com_custo_unitario_correto(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->import($user, true);
        $response->assertSessionHas('success');

        $created = Product::where('company_id', $user->company_id)->where('sku', 'SKU-B')->first();
        $this->assertNotNull($created);
        $this->assertSame('50.50', $created->cost_price);
        $this->assertSame(1, (int) $created->stock_quantity);
        $this->assertSame('Produto B', $created->title);
    }

    public function test_linha_sem_quantidade_nem_custo_nao_cria_produto_mesmo_com_create_missing(): void
    {
        $user = $this->authenticatedUser();

        $this->import($user, true);

        $this->assertNull(Product::where('company_id', $user->company_id)->where('sku', 'SKU-D')->first());
    }

    public function test_sem_create_missing_sku_desconhecido_e_apenas_reportado(): void
    {
        $user = $this->authenticatedUser();
        // Nenhum produto cadastrado -> nada é criado, resumo reporta "não encontrados".

        $response = $this->import($user, false);

        $this->assertSame(0, Product::where('company_id', $user->company_id)->count());
        $response->assertSessionHas('success');
        $this->assertStringContainsString('não encontrados', session('success'));
    }

    public function test_resumo_nunca_menciona_custo_r_como_fonte(): void
    {
        $user = $this->authenticatedUser();
        Product::create(['company_id' => $user->company_id, 'sku' => 'SKU-A', 'title' => 'Produto A']);

        $response = $this->import($user);

        $this->assertStringContainsString('CUSTO UNR', session('success'));
    }

    public function test_import_requires_authentication(): void
    {
        $response = $this->post(route('magazord.import', ['type' => 'inventario']), ['file' => $this->inventarioFile()]);
        $response->assertRedirect(route('login'));
    }
}
