<?php

namespace Tests\Feature\Ads;

use App\Services\Ads\AdsPerformanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Cruzamento gasto (ad_spend_daily) x receita atribuída via UTM
 * (orders.utm_*). Cobre as duas granularidades — por plataforma/dia (mapeia
 * utm_source -> plataforma) e por campanha (só casa nome exato) — e os
 * casos em que a atribuição NÃO deve ser fabricada: origem não reconhecida
 * e campanha sem correspondência dos dois lados (CLAUDE.md §2.2).
 */
class AdsPerformanceServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private AdsPerformanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = app(AdsPerformanceService::class);
    }

    private function order(array $overrides = []): void
    {
        DB::table('orders')->insert(array_merge([
            'company_id' => $this->companyId,
            'ml_order_id' => 'ORD-' . random_int(100000, 999999),
            'status' => 'delivered',
            'total_amount' => 100.0,
            'date_created' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function spend(array $overrides = []): void
    {
        DB::table('ad_spend_daily')->insert(array_merge([
            'company_id' => $this->companyId,
            'platform' => 'google_ads',
            'date' => now()->toDateString(),
            'campaign_name' => 'Campanha X',
            'spend' => 50.0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_overview_calcula_roas_por_plataforma(): void
    {
        $this->spend(['platform' => 'google_ads', 'campaign_name' => 'Campanha A', 'spend' => 100.0]);
        $this->order(['utm_source' => 'google', 'utm_campaign' => 'Campanha A', 'total_amount' => 300.0]);

        $overview = $this->service->overview($this->companyId, Carbon::now()->subDays(1), Carbon::now()->addDay());

        $this->assertTrue($overview['ready']);
        $googleAds = collect($overview['by_platform'])->firstWhere('platform', 'google_ads');
        $this->assertNotNull($googleAds);
        $this->assertSame(100.0, $googleAds['spend']);
        $this->assertSame(300.0, $googleAds['revenue']);
        $this->assertSame(3.0, $googleAds['roas']);
    }

    public function test_origem_nao_reconhecida_nao_e_atribuida_a_nenhuma_plataforma(): void
    {
        $this->spend(['platform' => 'google_ads', 'spend' => 50.0]);
        $this->order(['utm_source' => 'newsletter', 'total_amount' => 999.0]);

        $overview = $this->service->overview($this->companyId, Carbon::now()->subDays(1), Carbon::now()->addDay());

        $googleAds = collect($overview['by_platform'])->firstWhere('platform', 'google_ads');
        $this->assertSame(0, $googleAds['orders']);
        $this->assertSame(0.0, $googleAds['revenue']);
        $this->assertNull($googleAds['roas']);

        $unmapped = collect($overview['unmapped_sources'])->firstWhere('source', 'newsletter');
        $this->assertNotNull($unmapped);
        $this->assertSame(1, $unmapped['orders']);
    }

    public function test_campanha_so_casa_por_nome_exato_o_resto_fica_separado(): void
    {
        $this->spend(['platform' => 'meta_ads', 'campaign_name' => 'Promo Inverno', 'spend' => 80.0]);
        $this->order(['utm_source' => 'facebook', 'utm_campaign' => 'promo inverno', 'total_amount' => 200.0]); // casa (case-insensitive)
        $this->spend(['platform' => 'meta_ads', 'campaign_name' => 'Campanha Sem Venda', 'spend' => 40.0]); // spend_only
        $this->order(['utm_source' => 'facebook', 'utm_campaign' => 'Campanha Sem Gasto', 'total_amount' => 60.0]); // revenue_only

        $overview = $this->service->overview($this->companyId, Carbon::now()->subDays(1), Carbon::now()->addDay());

        $matched = collect($overview['campaigns']['matched'])->firstWhere('campaign', 'Promo Inverno');
        $this->assertNotNull($matched);
        $this->assertSame(200.0, $matched['revenue']);
        $this->assertSame(2.5, $matched['roas']);

        $this->assertTrue(collect($overview['campaigns']['spend_only'])->contains('campaign', 'Campanha Sem Venda'));
        $this->assertTrue(collect($overview['campaigns']['revenue_only'])->contains('campaign', 'Campanha Sem Gasto'));
    }

    public function test_pedido_cancelado_nao_entra_na_receita_atribuida(): void
    {
        $this->spend(['platform' => 'google_ads', 'spend' => 50.0]);
        $this->order(['utm_source' => 'google', 'status' => 'cancelled', 'total_amount' => 500.0]);

        $overview = $this->service->overview($this->companyId, Carbon::now()->subDays(1), Carbon::now()->addDay());

        $googleAds = collect($overview['by_platform'])->firstWhere('platform', 'google_ads');
        $this->assertSame(0.0, $googleAds['revenue']);
    }

    public function test_empty_quando_sem_dado_no_periodo(): void
    {
        $overview = $this->service->overview($this->companyId, Carbon::now()->subDays(1), Carbon::now()->addDay());

        $this->assertTrue($overview['ready']);
        $this->assertFalse($overview['has_spend_data']);
        $this->assertFalse($overview['has_utm_data']);
        $this->assertSame([], $overview['by_platform']);
    }
}
