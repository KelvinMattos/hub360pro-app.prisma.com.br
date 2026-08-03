<?php

namespace Tests\Feature\Sales;

use App\Models\ChannelSalesDaily;
use App\Services\Sales\SalesChannelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesChannelReportServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private SalesChannelReportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = new SalesChannelReportService();
    }

    private function seedDay(string $channel, string $date, array $overrides = []): void
    {
        ChannelSalesDaily::create(array_merge([
            'company_id' => $this->companyId,
            'channel' => $channel,
            'sale_date' => $date,
            'gross_value' => 100, 'paid_value' => 90, 'canceled_value' => 10,
            'fees' => 15, 'shipping_cost' => 5, 'net_value' => 70, 'orders_count' => 3,
        ], $overrides));
    }

    public function test_monthly_summary_soma_matriz_e_filial_no_total_mercado_livre(): void
    {
        $this->seedDay('mercado_livre_matriz', '2026-03-01', ['paid_value' => 100]);
        $this->seedDay('mercado_livre_filial', '2026-03-01', ['paid_value' => 50]);

        $summary = $this->service->monthlySummary($this->companyId, 2026);

        $this->assertSame(150.0, $summary['months'][3]['mercado_livre_total']['current']['paid_value']);
    }

    public function test_monthly_summary_diff_pct_e_null_sem_dado_do_ano_anterior(): void
    {
        $this->seedDay('netshoes', '2026-03-01', ['paid_value' => 100]);

        $summary = $this->service->monthlySummary($this->companyId, 2026);

        $this->assertNull($summary['months'][3]['netshoes']['diff_pct']);
        $this->assertNull($summary['months'][3]['netshoes']['previous']);
    }

    public function test_monthly_summary_calcula_diff_pct_quando_ano_anterior_existe(): void
    {
        $this->seedDay('netshoes', '2025-03-01', ['paid_value' => 100]);
        $this->seedDay('netshoes', '2026-03-01', ['paid_value' => 150]);

        $summary = $this->service->monthlySummary($this->companyId, 2026);

        $this->assertSame(0.5, $summary['months'][3]['netshoes']['diff_pct']); // +50%
    }

    public function test_monthly_summary_nao_fabrica_total_quando_nao_ha_nenhum_dado_no_mes(): void
    {
        $this->seedDay('netshoes', '2026-03-01', ['paid_value' => 100]);

        $summary = $this->service->monthlySummary($this->companyId, 2026);

        // Fevereiro não tem nenhum dado importado — total deve ser null, não 0.
        $this->assertNull($summary['months'][2]['total']['current']);
    }

    public function test_daily_lista_e_filtra_por_canal_e_periodo(): void
    {
        $this->seedDay('netshoes', '2026-03-01');
        $this->seedDay('site', '2026-03-01');
        $this->seedDay('netshoes', '2026-04-01');

        $rows = $this->service->daily($this->companyId, 'netshoes', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $rows);
        $this->assertSame('netshoes', $rows[0]['channel']);
    }

    public function test_weekly_summary_particiona_o_mes_em_semanas_sabado_a_sexta(): void
    {
        // Março/2026: dia 1 é domingo — primeira "semana" fica 01/03 (parcial), depois sáb-sex.
        $this->seedDay('netshoes', '2026-03-01', ['paid_value' => 10]);
        $this->seedDay('netshoes', '2026-03-07', ['paid_value' => 20]); // sábado
        $this->seedDay('netshoes', '2026-03-31', ['paid_value' => 30]);

        $summary = $this->service->weeklySummary($this->companyId, 2026, 3);

        $lastWeek = end($summary['weeks']);
        $this->assertSame(60.0, $lastWeek['accumulated']['value']);
    }

    public function test_mercado_livre_accounts_retorna_matriz_e_filial_separados(): void
    {
        $this->seedDay('mercado_livre_matriz', '2026-03-01', ['paid_value' => 100]);
        $this->seedDay('mercado_livre_filial', '2026-03-01', ['paid_value' => 40]);

        $result = $this->service->mercadoLivreAccounts($this->companyId, 2026);

        $this->assertSame(100.0, $result['accounts']['mercado_livre_matriz']['months'][3]['current']['paid_value']);
        $this->assertSame(40.0, $result['accounts']['mercado_livre_filial']['months'][3]['current']['paid_value']);
    }

    public function test_goals_salva_e_le_meta_por_canal_mes_ano(): void
    {
        $this->service->saveGoal($this->companyId, 'netshoes', 2026, 3, 5000.0);

        $goals = $this->service->goalsFor($this->companyId, 2026);

        $this->assertSame(5000.0, $goals['netshoes:3']);
    }
}
