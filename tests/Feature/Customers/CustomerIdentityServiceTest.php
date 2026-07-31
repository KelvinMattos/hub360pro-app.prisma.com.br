<?php

namespace Tests\Feature\Customers;

use App\Services\Customers\CustomerIdentityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    private int $companyId;
    private CustomerIdentityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Teste', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->service = app(CustomerIdentityService::class);
    }

    public function test_normalize_doc_strips_punctuation(): void
    {
        $this->assertSame('12345678900', CustomerIdentityService::normalizeDoc('123.456.789-00'));
        $this->assertSame('12345678900', CustomerIdentityService::normalizeDoc('12345678900'));
        $this->assertNull(CustomerIdentityService::normalizeDoc(null));
        $this->assertNull(CustomerIdentityService::normalizeDoc(''));
    }

    public function test_find_or_create_creates_customer_with_normalized_doc(): void
    {
        $customer = $this->service->findOrCreate($this->companyId, [
            'doc_number' => '123.456.789-00', 'name' => 'Maria', 'origin_channel' => 'magazord',
        ]);

        $this->assertNotNull($customer);
        $this->assertSame('12345678900', $customer->doc_number);
        $this->assertSame('Maria', $customer->name);
        $this->assertSame('CPF', $customer->doc_type);
    }

    public function test_find_or_create_matches_existing_customer_by_normalized_doc_regardless_of_format(): void
    {
        $first = $this->service->findOrCreate($this->companyId, [
            'doc_number' => '123.456.789-00', 'name' => 'Maria', 'origin_channel' => 'magazord',
        ]);
        $second = $this->service->findOrCreate($this->companyId, [
            'doc_number' => '12345678900', 'name' => 'Maria', 'origin_channel' => 'shopee',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, DB::table('customers')->where('company_id', $this->companyId)->count());
    }

    public function test_find_or_create_respects_company_isolation(): void
    {
        $otherCompanyId = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);

        $this->service->findOrCreate($this->companyId, ['doc_number' => '11111111111', 'name' => 'A']);
        $customer = $this->service->findOrCreate($otherCompanyId, ['doc_number' => '11111111111', 'name' => 'B']);

        $this->assertSame(2, DB::table('customers')->where('doc_number', '11111111111')->count());
        $this->assertSame($otherCompanyId, $customer->company_id);
    }

    public function test_find_or_create_falls_back_to_external_id_and_channel_without_doc(): void
    {
        $first = $this->service->findOrCreate($this->companyId, [
            'external_id' => 'ML123', 'name' => 'Sem CPF', 'origin_channel' => 'mercadolivre',
        ]);
        $second = $this->service->findOrCreate($this->companyId, [
            'external_id' => 'ML123', 'name' => 'Sem CPF', 'origin_channel' => 'mercadolivre',
        ]);

        $this->assertSame($first->id, $second->id);
    }

    public function test_find_or_create_external_id_fallback_does_not_cross_channels(): void
    {
        $ml = $this->service->findOrCreate($this->companyId, [
            'external_id' => 'SAME123', 'name' => 'Comprador', 'origin_channel' => 'mercadolivre',
        ]);
        $shopee = $this->service->findOrCreate($this->companyId, [
            'external_id' => 'SAME123', 'name' => 'Comprador', 'origin_channel' => 'shopee',
        ]);

        $this->assertNotSame($ml->id, $shopee->id);
    }

    public function test_find_or_create_returns_null_without_any_identifier(): void
    {
        $customer = $this->service->findOrCreate($this->companyId, ['name' => 'Só nome, sem CPF nem external_id']);

        $this->assertNull($customer);
    }

    public function test_find_or_create_updates_missing_fields_on_existing_customer(): void
    {
        $first = $this->service->findOrCreate($this->companyId, ['doc_number' => '11111111111', 'name' => 'Nome Antigo']);
        $updated = $this->service->findOrCreate($this->companyId, [
            'doc_number' => '11111111111', 'name' => 'Nome Antigo', 'email' => 'novo@example.com', 'city' => 'São Paulo',
        ]);

        $this->assertSame($first->id, $updated->id);
        $this->assertSame('novo@example.com', $updated->email);
        $this->assertSame('São Paulo', $updated->city);
    }
}
