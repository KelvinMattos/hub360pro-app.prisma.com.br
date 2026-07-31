<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CommercialDateControllerTest extends TestCase
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

    public function test_index_merges_global_and_company_dates(): void
    {
        DB::table('commercial_dates')->insert([
            'company_id' => null, 'date' => '2026-01-01', 'title' => 'Ano Novo Global',
            'category' => 'feriado', 'recurring_yearly' => true, 'source' => 'seed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('commercial_dates')->insert([
            'company_id' => $this->companyId, 'date' => '2026-09-20', 'title' => 'Aniversário da Loja',
            'category' => 'proprio', 'recurring_yearly' => true, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('commercial_dates')->insert([
            'company_id' => $otherCompany, 'date' => '2026-05-05', 'title' => 'De outra empresa',
            'category' => 'proprio', 'recurring_yearly' => true, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get(route('marketing.calendar.index'));

        $response->assertOk();
        $response->assertInertia(function (Assert $page) {
            $titles = collect($page->toArray()['props']['dates'])->pluck('title');
            $this->assertTrue($titles->contains('Ano Novo Global'));
            $this->assertTrue($titles->contains('Aniversário da Loja'));
            $this->assertFalse($titles->contains('De outra empresa'));
        });
    }

    public function test_store_creates_company_date(): void
    {
        $response = $this->actingAs($this->user)->post(route('marketing.calendar.store'), [
            'title' => 'Semana do Cliente', 'date' => '2026-09-10', 'category' => 'sazonal', 'recurring_yearly' => true,
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('commercial_dates', [
            'company_id' => $this->companyId, 'title' => 'Semana do Cliente', 'source' => 'manual',
        ]);
    }

    public function test_cannot_delete_global_seed_date(): void
    {
        $id = DB::table('commercial_dates')->insertGetId([
            'company_id' => null, 'date' => '2026-12-25', 'title' => 'Natal',
            'category' => 'feriado', 'recurring_yearly' => true, 'source' => 'seed',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->delete(route('marketing.calendar.destroy', $id));

        $response->assertNotFound();
        $this->assertDatabaseHas('commercial_dates', ['id' => $id]);
    }

    public function test_can_delete_own_company_date(): void
    {
        $id = DB::table('commercial_dates')->insertGetId([
            'company_id' => $this->companyId, 'date' => '2026-09-10', 'title' => 'Data própria',
            'category' => 'proprio', 'recurring_yearly' => true, 'source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->delete(route('marketing.calendar.destroy', $id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('commercial_dates', ['id' => $id]);
    }

    public function test_import_creates_dates_from_csv(): void
    {
        $csv = "Título;Data;Categoria;Recorrente\nDia da Loja;15/09/2026;proprio;sim\nEvento Único;20/10/2026;sazonal;nao\n";
        $file = UploadedFile::fake()->createWithContent('datas.csv', $csv);

        $response = $this->actingAs($this->user)->post(route('marketing.calendar.import'), ['file' => $file]);

        $response->assertRedirect();
        $this->assertDatabaseHas('commercial_dates', [
            'company_id' => $this->companyId, 'title' => 'Dia da Loja', 'date' => '2026-09-15', 'recurring_yearly' => 1,
        ]);
        $this->assertDatabaseHas('commercial_dates', [
            'company_id' => $this->companyId, 'title' => 'Evento Único', 'date' => '2026-10-20', 'recurring_yearly' => 0,
        ]);
    }

    public function test_import_skips_rows_without_title_or_date(): void
    {
        $csv = "Título;Data;Categoria;Recorrente\n;15/09/2026;proprio;sim\nSem data;;proprio;sim\n";
        $file = UploadedFile::fake()->createWithContent('datas.csv', $csv);

        $response = $this->actingAs($this->user)->post(route('marketing.calendar.import'), ['file' => $file]);

        $response->assertRedirect();
        $this->assertSame(0, DB::table('commercial_dates')->where('company_id', $this->companyId)->count());
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('marketing.calendar.index'));
        $response->assertRedirect(route('login'));
    }
}
