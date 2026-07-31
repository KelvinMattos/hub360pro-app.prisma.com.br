<?php

namespace Tests\Feature\Marketing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskControllerTest extends TestCase
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

    public function test_store_creates_task_with_todo_status(): void
    {
        $response = $this->actingAs($this->user)->post(route('marketing.tasks.store'), [
            'title' => 'Criar banner', 'priority' => 'alta', 'due_date' => now()->addDays(3)->toDateString(),
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('marketing_tasks', [
            'company_id' => $this->companyId, 'title' => 'Criar banner', 'status' => 'todo', 'priority' => 'alta',
        ]);
    }

    public function test_update_status_to_done_sets_completed_at(): void
    {
        $taskId = DB::table('marketing_tasks')->insertGetId([
            'company_id' => $this->companyId, 'title' => 'Tarefa', 'status' => 'todo', 'priority' => 'media',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->patch(route('marketing.tasks.update', $taskId), ['status' => 'done']);

        $task = DB::table('marketing_tasks')->find($taskId);
        $this->assertSame('done', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_update_status_away_from_done_clears_completed_at(): void
    {
        $taskId = DB::table('marketing_tasks')->insertGetId([
            'company_id' => $this->companyId, 'title' => 'Tarefa', 'status' => 'done', 'priority' => 'media',
            'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->patch(route('marketing.tasks.update', $taskId), ['status' => 'doing']);

        $task = DB::table('marketing_tasks')->find($taskId);
        $this->assertSame('doing', $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_cannot_update_task_from_another_company(): void
    {
        $otherCompany = DB::table('companies')->insertGetId(['name' => 'Outra', 'created_at' => now(), 'updated_at' => now()]);
        $taskId = DB::table('marketing_tasks')->insertGetId([
            'company_id' => $otherCompany, 'title' => 'De outra empresa', 'status' => 'todo', 'priority' => 'media',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->patch(route('marketing.tasks.update', $taskId), ['status' => 'done']);

        $response->assertNotFound();
    }

    public function test_destroy_removes_task(): void
    {
        $taskId = DB::table('marketing_tasks')->insertGetId([
            'company_id' => $this->companyId, 'title' => 'Tarefa', 'status' => 'todo', 'priority' => 'media',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->user)->delete(route('marketing.tasks.destroy', $taskId));

        $this->assertDatabaseMissing('marketing_tasks', ['id' => $taskId]);
    }

    public function test_index_filters_by_status_and_assignee(): void
    {
        $other = User::factory()->create(['company_id' => $this->companyId]);
        DB::table('marketing_tasks')->insert([
            ['company_id' => $this->companyId, 'title' => 'Minha tarefa', 'assignee_id' => $this->user->id, 'status' => 'todo', 'priority' => 'media', 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->companyId, 'title' => 'Tarefa do outro', 'assignee_id' => $other->id, 'status' => 'done', 'priority' => 'media', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $response = $this->actingAs($this->user)->get(route('marketing.tasks.index', ['assignee_id' => $this->user->id]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->has('tasks', 1)->where('tasks.0.title', 'Minha tarefa'));
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get(route('marketing.tasks.index'));
        $response->assertRedirect(route('login'));
    }
}
