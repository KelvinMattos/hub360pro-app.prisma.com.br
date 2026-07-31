<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/** Tarefas do time de marketing — soltas ou vinculadas a uma campanha. */
class TaskController extends Controller
{
    private const STATUSES = ['todo', 'doing', 'done'];
    private const PRIORITIES = ['baixa', 'media', 'alta'];

    public function index(Request $request)
    {
        $companyId = Auth::user()?->company_id;

        $q = DB::table('marketing_tasks as t')
            ->leftJoin('marketing_campaigns as c', 'c.id', '=', 't.campaign_id')
            ->where('t.company_id', $companyId);

        if ($assignee = $request->get('assignee_id')) {
            $q->where('t.assignee_id', $assignee);
        }
        if ($status = $request->get('status')) {
            $q->where('t.status', $status);
        }

        $tasks = $q->select('t.*', 'c.name as campaign_name')->orderByRaw('t.status = "done"')
            ->orderByRaw('t.due_date IS NULL, t.due_date')->get();

        $owners = $companyId ? DB::table('users')->where('company_id', $companyId)->pluck('name', 'id') : collect();

        $rows = $tasks->map(fn ($t) => (array) $t + [
            'assignee_name' => $t->assignee_id ? ($owners[$t->assignee_id] ?? null) : null,
        ]);

        return Inertia::render('Marketing/Tasks/Index', [
            'tasks' => $rows->values()->all(),
            'statuses' => self::STATUSES,
            'priorities' => self::PRIORITIES,
            'users' => $companyId ? DB::table('users')->where('company_id', $companyId)->select('id', 'name')->get() : [],
            'campaigns' => $companyId ? DB::table('marketing_campaigns')->where('company_id', $companyId)->select('id', 'name')->get() : [],
            'filters' => $request->only(['assignee_id', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->withErrors(['company' => 'Empresa não identificada.']);
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'campaign_id' => ['nullable', 'integer'],
            'assignee_id' => ['nullable', 'integer'],
            'priority' => ['nullable', 'string', 'in:' . implode(',', self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        DB::table('marketing_tasks')->insert([
            'company_id' => $companyId,
            'campaign_id' => $data['campaign_id'] ?? null,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'assignee_id' => $data['assignee_id'] ?? null,
            'priority' => $data['priority'] ?? 'media',
            'status' => 'todo',
            'due_date' => $data['due_date'] ?? null,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Tarefa criada.');
    }

    public function update(Request $request, int $task)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_tasks')->where('id', $task)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'assignee_id' => ['nullable', 'integer'],
            'status' => ['sometimes', 'string', 'in:' . implode(',', self::STATUSES)],
            'priority' => ['sometimes', 'string', 'in:' . implode(',', self::PRIORITIES)],
            'due_date' => ['nullable', 'date'],
        ]);

        $data['updated_at'] = now();
        if (isset($data['status'])) {
            $data['completed_at'] = $data['status'] === 'done' ? now() : null;
        }

        DB::table('marketing_tasks')->where('id', $task)->update($data);

        return back()->with('success', 'Tarefa atualizada.');
    }

    public function destroy(int $task)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_tasks')->where('id', $task)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        DB::table('marketing_tasks')->where('id', $task)->delete();

        return back()->with('success', 'Tarefa removida.');
    }
}
