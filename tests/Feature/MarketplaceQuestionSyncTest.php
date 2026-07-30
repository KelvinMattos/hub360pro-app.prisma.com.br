<?php

namespace Tests\Feature;

use App\Models\Integration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MarketplaceQuestionService::syncQuestions() engolia qualquer exceção (catch
 * Exception + Log::error, sem retorno) e o controller sempre respondia
 * "Sincronização iniciada." — mesmo padrão do bug já corrigido em
 * MarketplaceListingService::syncListings().
 */
class MarketplaceQuestionSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['features.marketplaces_questions' => true]);

        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['company_id' => $this->companyId]);
    }

    private function makeCredential(array $overrides = []): Integration
    {
        return Integration::create(array_merge([
            'company_id' => $this->companyId,
            'platform' => Integration::PLATFORM_MERCADO_LIVRE,
            'seller_id' => '123',
            'access_token' => 'token',
            'refresh_token' => 'refresh-token',
            'is_active' => true,
            'account_nickname' => 'Conta Teste',
        ], $overrides));
    }

    public function test_sync_reports_real_error_when_token_refresh_fails(): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        // Sem expires_at -> isNearExpiration() true -> tenta refreshToken() antes do request.
        $this->makeCredential();

        $response = $this->actingAs($this->user)->post(route('marketplaces.questions.sync'));

        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
        $this->assertStringContainsString('Mercado Livre authentication failed', session('error'));
    }

    public function test_sync_reports_real_success_and_persists_question(): void
    {
        Http::fake([
            'api.mercadolibre.com/questions/search*' => Http::response(['questions' => [
                [
                    'id' => 'Q1',
                    'item_id' => 'MLB1',
                    'text' => 'Tem estoque?',
                    'status' => 'unanswered',
                    'date_created' => now()->toIso8601String(),
                    'from' => ['name' => 'Comprador Teste'],
                ],
            ]]),
            'api.mercadolibre.com/answers' => Http::response(['id' => 'A1']),
        ]);

        $credential = $this->makeCredential(['expires_at' => now()->addDay(), 'token_expires_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->post(route('marketplaces.questions.sync'));

        $response->assertSessionHas('success');
        $response->assertSessionMissing('error');
        $this->assertDatabaseHas('marketplace_questions', [
            'company_id' => $this->companyId,
            'integration_id' => $credential->id,
            'external_id' => 'Q1',
        ]);
    }

    public function test_sync_reports_error_when_no_active_integration_exists(): void
    {
        $response = $this->actingAs($this->user)->post(route('marketplaces.questions.sync'));

        $response->assertSessionHas('error');
        $response->assertSessionMissing('success');
    }

    public function test_sync_reports_partial_failure_when_only_some_integrations_fail(): void
    {
        Http::fake([
            'api.mercadolibre.com/oauth/token' => Http::response(['error' => 'invalid_grant'], 400),
            'api.mercadolibre.com/questions/search*' => Http::response(['questions' => []]),
        ]);

        // Uma credencial sem expires_at falha o refresh; outra já válida sincroniza (0 perguntas, mas com sucesso).
        $this->makeCredential(['seller_id' => '1']);
        $this->makeCredential(['seller_id' => '2', 'expires_at' => now()->addDay(), 'token_expires_at' => now()->addDay()]);

        $response = $this->actingAs($this->user)->post(route('marketplaces.questions.sync'));

        $response->assertSessionHas('success');
        $this->assertStringContainsString('falha parcial', session('success'));
    }
}
