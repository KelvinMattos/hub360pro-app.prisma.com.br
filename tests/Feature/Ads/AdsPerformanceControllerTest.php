<?php

namespace Tests\Feature\Ads;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdsPerformanceControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('ads.dashboard'))->assertRedirect(route('login'));
    }

    public function test_dashboard_renderiza_com_periodo_padrao_de_30_dias(): void
    {
        $companyId = DB::table('companies')->insertGetId(['name' => 'Empresa', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create(['company_id' => $companyId]);

        $response = $this->actingAs($user)->get(route('ads.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Ads/Dashboard')
            ->has('overview')
            ->has('platforms', 2)
        );
    }
}
