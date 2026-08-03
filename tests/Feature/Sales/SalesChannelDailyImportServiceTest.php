<?php

namespace Tests\Feature\Sales;

use App\Models\ChannelSalesDaily;
use App\Services\Sales\SalesChannelDailyImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Validado contra o arquivo real do cliente (DIÁRIO_DE_VENDAS__MAR26.xls,
 * 13 abas) antes de escrever estes testes: os 7 campos batem exatamente
 * com a linha TOTAIS de cada aba, exceto numa descoberta real — a aba
 * "MERCADO LIVRE - FILIAL" tinha a fórmula da própria TOTAIS truncada
 * (`=SUM(C3:C30)` em vez de `C3:C33`, faltando os últimos 3 dias do mês)
 * nas colunas PAGOS e TOTAL LÍQUIDO. Por isso o importador nunca lê a
 * linha TOTAIS — soma sempre os dados diários reais, que é o número
 * correto mesmo quando o "resumo" da própria planilha do cliente está
 * errado (CLAUDE.md §2.2: nunca gravar valor que não veio comprovado
 * da fonte real).
 *
 * O fixture usado aqui (sales-channel-diario-sample.xlsx) é sintético, mas
 * reproduz a mesma característica que causou um bug real durante o
 * desenvolvimento: a coluna PAGOS é uma FÓRMULA (`=EFETUADOS-CANCELADOS`),
 * não um valor literal — getValue() devolve a fórmula, só
 * getCalculatedValue() dá o número certo. Ver SalesChannelDailyImportService.
 */
class SalesChannelDailyImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->fixture = base_path('tests/Fixtures/sales-channel-diario-sample.xlsx');
    }

    public function test_importa_abas_reconhecidas_e_ignora_aba_desconhecida(): void
    {
        $service = new SalesChannelDailyImportService();
        $result = $service->import($this->companyId, $this->fixture, 'sample.xlsx');

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['sheets_total']);
        $this->assertSame(2, $result['sheets_recognized']);
        $this->assertSame(['LOJA MISTERIOSA - (JAN-26)'], $result['sheets_ignored']);
        $this->assertSame(5, $result['rows_imported']); // 3 dias netshoes + 2 dias site
    }

    public function test_calcula_colunas_de_formula_via_getCalculatedValue(): void
    {
        // A coluna PAGOS do fixture é a fórmula "=EFETUADOS-CANCELADOS" — se o
        // import voltasse a usar getValue() em vez de getCalculatedValue(),
        // este teste pega a regressão (paid_value ficaria 0 pra tudo).
        $service = new SalesChannelDailyImportService();
        $service->import($this->companyId, $this->fixture, 'sample.xlsx');

        $day1 = ChannelSalesDaily::where('company_id', $this->companyId)
            ->where('channel', 'netshoes')->where('sale_date', '2026-01-01')->first();

        $this->assertNotNull($day1);
        $this->assertSame(1000.0, $day1->gross_value);
        $this->assertSame(100.0, $day1->canceled_value);
        $this->assertSame(900.0, $day1->paid_value); // 1000 - 100, via fórmula
    }

    public function test_reimportar_o_mesmo_arquivo_atualiza_em_vez_de_duplicar(): void
    {
        $service = new SalesChannelDailyImportService();
        $service->import($this->companyId, $this->fixture, 'sample.xlsx');
        $countAfterFirst = ChannelSalesDaily::where('company_id', $this->companyId)->count();

        $service->import($this->companyId, $this->fixture, 'sample.xlsx');
        $countAfterSecond = ChannelSalesDaily::where('company_id', $this->companyId)->count();

        $this->assertSame(5, $countAfterFirst);
        $this->assertSame($countAfterFirst, $countAfterSecond);
    }

    public function test_registra_o_arquivo_de_origem_para_auditoria(): void
    {
        $service = new SalesChannelDailyImportService();
        $service->import($this->companyId, $this->fixture, 'diario-jan-2026.xlsx');

        $row = ChannelSalesDaily::where('company_id', $this->companyId)->first();
        $this->assertSame('diario-jan-2026.xlsx', $row->source_file);
    }

    public function test_nao_mistura_dados_entre_empresas_diferentes(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId([
            'name' => 'Outra Empresa', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $service = new SalesChannelDailyImportService();
        $service->import($this->companyId, $this->fixture, 'sample.xlsx');
        $service->import($otherCompanyId, $this->fixture, 'sample.xlsx');

        $this->assertSame(5, ChannelSalesDaily::where('company_id', $this->companyId)->count());
        $this->assertSame(5, ChannelSalesDaily::where('company_id', $otherCompanyId)->count());
    }
}
