<?php

namespace Tests\Unit;

use App\Services\Imports\ImportDetectionService;
use Tests\TestCase;

/**
 * Central de Importações (pedido do cliente 05/08/2026 — "não precisando eu
 * ficar escolhendo qual é"): detecta automaticamente pra qual das ~20 telas
 * de importação um arquivo pertence, a partir do cabeçalho.
 *
 * Estes testes usam os MESMOS headers já declarados nas TYPES const dos
 * controllers (fonte única, ver classe) — não uma cópia paralela.
 */
class ImportDetectionServiceTest extends TestCase
{
    private ImportDetectionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ImportDetectionService();
    }

    public function test_detecta_magazord_custos_com_confianca(): void
    {
        $header = ['Id Der.', 'Código', 'Produto', 'Qtd Física', 'Valor Atual', 'Valor Estoque', 'Produto Ativo'];

        $result = $this->service->detect($header);

        $this->assertSame('confident', $result['status']);
        $this->assertSame('magazord', $result['match']['source']);
        $this->assertSame('custos', $result['match']['type']);
    }

    public function test_detecta_inventario_geral_e_nao_confunde_com_custos(): void
    {
        $header = ['COD', 'NCM', 'Descrição do Produto', 'TAM', 'Quantidade', 'Unid', 'Custo Unr', 'Custo R$'];

        $result = $this->service->detect($header);

        $this->assertSame('confident', $result['status']);
        $this->assertSame('magazord', $result['match']['source']);
        $this->assertSame('inventario', $result['match']['type']);
    }

    public function test_detecta_vendas_mercado_livre(): void
    {
        $header = ['N.º de venda', 'Data da venda', 'Estado', 'SKU', 'Título do anúncio', 'Unidades',
            'Preço unitário de venda do anúncio (BRL)', 'Tarifa de venda e impostos (BRL)', 'Tarifas de envio (BRL)', 'Comprador', 'CPF'];

        $result = $this->service->detect($header);

        $this->assertSame('confident', $result['status']);
        $this->assertSame('order_channel', $result['match']['source']);
        $this->assertSame('mercado_livre', $result['match']['type']);
        $this->assertTrue($result['match']['needs_account']);
    }

    public function test_detecta_netshoes_precos_e_nao_confunde_com_estoque(): void
    {
        $header = ['Sku Seller', 'Preço De', 'Preço Por'];

        $result = $this->service->detect($header);

        $this->assertSame('confident', $result['status']);
        $this->assertSame('netshoes', $result['match']['source']);
        $this->assertSame('precos', $result['match']['type']);
    }

    public function test_detecta_diario_de_vendas_por_canal_pelas_abas(): void
    {
        $result = $this->service->detect([], ['MERCADO LIVRE - MATRIZ (MAR-26)', 'SHOPEE (MAR-26)', 'SITE (MAR-26)']);

        $this->assertSame('confident', $result['status']);
        $this->assertSame('sales_channel', $result['match']['source']);
    }

    public function test_cabecalho_sem_relacao_nenhuma_e_desconhecido(): void
    {
        $result = $this->service->detect(['Coluna Aleatória 1', 'Outra Coisa Qualquer', 'Nada a Ver']);

        $this->assertSame('unknown', $result['status']);
        $this->assertNull($result['match']);
    }

    public function test_cabecalho_vazio_e_desconhecido(): void
    {
        $result = $this->service->detect([]);

        $this->assertSame('unknown', $result['status']);
    }

    public function test_todo_tipo_do_catalogo_tem_rota_de_import_e_show(): void
    {
        foreach ($this->service->catalog() as $entry) {
            $this->assertNotEmpty($entry['show_route'], "source={$entry['source']} type=" . ($entry['type'] ?? 'null'));
            $this->assertNotEmpty($entry['import_route'], "source={$entry['source']} type=" . ($entry['type'] ?? 'null'));
        }
    }
}
