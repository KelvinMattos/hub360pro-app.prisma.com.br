<?php

namespace Tests\Feature\Sales;

use App\Models\ChannelSalesDaily;
use App\Models\ChannelSalesGoal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesChannelPerformanceControllerTest extends TestCase
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

        ChannelSalesDaily::create([
            'company_id' => $this->companyId, 'channel' => 'netshoes', 'sale_date' => '2026-03-01',
            'gross_value' => 1000, 'paid_value' => 900, 'canceled_value' => 100,
            'fees' => 50, 'shipping_cost' => 20, 'net_value' => 830, 'orders_count' => 5,
        ]);
    }

    public function test_index_requires_authentication(): void
    {
        $this->get(route('sales.channel-performance.index'))->assertRedirect(route('login'));
    }

    public function test_index_renderiza_dashboard_com_dados_calculados(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-performance.index', ['year' => 2026, 'month' => 3]));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('SalesChannel/Dashboard')
            ->where('year', 2026)
            ->where('month', 3)
            ->where('monthly.months.3.netshoes.current.paid_value', 900)
            ->has('daily', 1)
        );
    }

    public function test_export_xlsx_retorna_arquivo_com_content_type_correto(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-performance.export', ['view' => 'monthly', 'format' => 'xlsx', 'year' => 2026]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_export_csv_retorna_arquivo_csv(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-performance.export', ['view' => 'daily', 'format' => 'csv', 'year' => 2026, 'month' => 3]));

        $response->assertOk();
        $this->assertCsvHasCanalHeader($response);
    }

    private function assertCsvHasCanalHeader($response): void
    {
        $content = $response->streamedContent();
        $this->assertStringContainsString('Canal', $content);
    }

    public function test_export_pdf_retorna_pdf(): void
    {
        $response = $this->actingAs($this->user)->get(route('sales.channel-performance.export', ['view' => 'monthly', 'format' => 'pdf', 'year' => 2026]));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_save_goal_persiste_meta_do_canal(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.channel-performance.goals.save'), [
            'channel' => 'netshoes', 'year' => 2026, 'month' => 3, 'goal_amount' => 5000,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        $this->assertDatabaseHas('channel_sales_goals', [
            'company_id' => $this->companyId, 'channel' => 'netshoes', 'year' => 2026, 'month' => 3, 'goal_amount' => 5000,
        ]);
    }

    public function test_save_goal_rejeita_canal_invalido(): void
    {
        $response = $this->actingAs($this->user)->post(route('sales.channel-performance.goals.save'), [
            'channel' => 'canal-que-nao-existe', 'year' => 2026, 'month' => 3, 'goal_amount' => 5000,
        ]);

        $response->assertSessionHas('error');
        $this->assertSame(0, ChannelSalesGoal::count());
    }
}
