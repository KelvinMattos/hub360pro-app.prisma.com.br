<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * Campanhas de marketing — o Kanban (`stage`) e a ponte entre o motor de
 * oportunidades e uma ação real do time (criar campanha já com os produtos
 * sugeridos vinculados).
 */
class CampaignController extends Controller
{
    private const STAGES = ['ideia', 'planejamento', 'execucao', 'revisao', 'concluido'];
    private const TYPES = ['lancamento', 'liquidacao', 'sazonal', 'recorrente', 'outro'];

    public function index()
    {
        $companyId = Auth::user()?->company_id;

        $campaigns = $companyId
            ? DB::table('marketing_campaigns')->where('company_id', $companyId)->orderByDesc('updated_at')->get()
            : collect();

        $campaignIds = $campaigns->pluck('id');
        $productCounts = $campaignIds->isEmpty() ? collect() : DB::table('marketing_campaign_products')
            ->whereIn('campaign_id', $campaignIds)->select('campaign_id', DB::raw('count(*) as c'))
            ->groupBy('campaign_id')->pluck('c', 'campaign_id');
        $taskCounts = $campaignIds->isEmpty() ? collect() : DB::table('marketing_tasks')
            ->whereIn('campaign_id', $campaignIds)->select('campaign_id',
                DB::raw('count(*) as total'), DB::raw("sum(case when status = 'done' then 1 else 0 end) as done"))
            ->groupBy('campaign_id')->get()->keyBy('campaign_id');

        $owners = $companyId ? DB::table('users')->where('company_id', $companyId)->pluck('name', 'id') : collect();

        $rows = $campaigns->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->name, 'description' => $c->description,
            'type' => $c->type, 'stage' => $c->stage, 'color' => $c->color,
            'start_date' => $c->start_date, 'end_date' => $c->end_date,
            'owner_id' => $c->owner_id, 'owner_name' => $c->owner_id ? ($owners[$c->owner_id] ?? null) : null,
            'source_opportunity' => $c->source_opportunity,
            'product_count' => (int) ($productCounts[$c->id] ?? 0),
            'task_total' => (int) ($taskCounts[$c->id]->total ?? 0),
            'task_done' => (int) ($taskCounts[$c->id]->done ?? 0),
        ]);

        return Inertia::render('Marketing/Campaigns/Kanban', [
            'stages' => self::STAGES,
            'types' => self::TYPES,
            'campaigns' => $rows->all(),
            'users' => $companyId ? DB::table('users')->where('company_id', $companyId)->select('id', 'name')->get() : [],
        ]);
    }

    public function store(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->withErrors(['company' => 'Empresa não identificada.']);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:' . implode(',', self::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        DB::table('marketing_campaigns')->insert([
            'company_id' => $companyId,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'outro',
            'stage' => 'ideia',
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'owner_id' => $data['owner_id'] ?? null,
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return back()->with('success', 'Campanha criada.');
    }

    /** Cria uma campanha já a partir de uma sugestão do motor de oportunidades, com os produtos vinculados. */
    public function createFromOpportunity(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->withErrors(['company' => 'Empresa não identificada.']);
        }

        $data = $request->validate([
            'opportunity' => ['required', 'string', 'in:lancamento,mais_vendido,liquidar'],
            'name' => ['required', 'string', 'max:255'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $typeMap = ['lancamento' => 'lancamento', 'mais_vendido' => 'outro', 'liquidar' => 'liquidacao'];
        $actionMap = ['lancamento' => 'destacar', 'mais_vendido' => 'anunciar', 'liquidar' => 'liquidar'];

        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $companyId,
            'name' => $data['name'],
            'type' => $typeMap[$data['opportunity']],
            'stage' => 'ideia',
            'source_opportunity' => $data['opportunity'],
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $products = DB::table('products')->where('company_id', $companyId)
            ->whereIn('id', $data['product_ids'])->select('id')->get();

        $rows = $products->map(fn ($p) => [
            'campaign_id' => $campaignId, 'product_id' => $p->id,
            'suggested_action' => $actionMap[$data['opportunity']],
            'created_at' => now(), 'updated_at' => now(),
        ])->all();

        if (!empty($rows)) {
            DB::table('marketing_campaign_products')->insert($rows);
        }

        return redirect()->route('marketing.campaigns.show', $campaignId)
            ->with('success', 'Campanha criada com ' . count($rows) . ' produto(s) já vinculado(s).');
    }

    public function show(int $campaign)
    {
        $companyId = Auth::user()?->company_id;
        $c = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->first();
        abort_unless($c, 404);

        $products = DB::table('marketing_campaign_products as mcp')
            ->join('products as p', 'p.id', '=', 'mcp.product_id')
            ->where('mcp.campaign_id', $campaign)
            ->select('mcp.id as link_id', 'p.id as product_id', 'p.sku', 'p.title', 'p.brand', 'p.sale_price',
                'p.stock_quantity', 'mcp.suggested_action', 'mcp.discount_pct', 'mcp.notes')
            ->get();

        $owners = DB::table('users')->where('company_id', $companyId)->pluck('name', 'id');

        $tasks = DB::table('marketing_tasks')->where('campaign_id', $campaign)
            ->orderByRaw('status = "done"')->orderByRaw('due_date IS NULL, due_date')
            ->get()
            ->map(fn ($t) => (array) $t + ['assignee_name' => $t->assignee_id ? ($owners[$t->assignee_id] ?? null) : null]);

        return Inertia::render('Marketing/Campaigns/Show', [
            'campaign' => (array) $c,
            'products' => $products,
            'tasks' => $tasks->values()->all(),
            'stages' => self::STAGES,
            'types' => self::TYPES,
            'users' => DB::table('users')->where('company_id', $companyId)->select('id', 'name')->get(),
        ]);
    }

    public function update(Request $request, int $campaign)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'in:' . implode(',', self::TYPES)],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer'],
        ]);

        DB::table('marketing_campaigns')->where('id', $campaign)->update($data + ['updated_at' => now()]);

        return back()->with('success', 'Campanha atualizada.');
    }

    /** PATCH só do stage — usado pelo drag-and-drop do Kanban. */
    public function updateStage(Request $request, int $campaign)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        $data = $request->validate(['stage' => ['required', 'string', 'in:' . implode(',', self::STAGES)]]);

        DB::table('marketing_campaigns')->where('id', $campaign)->update(['stage' => $data['stage'], 'updated_at' => now()]);

        return back()->with('success', 'Etapa atualizada.');
    }

    public function destroy(int $campaign)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        DB::table('marketing_campaign_products')->where('campaign_id', $campaign)->delete();
        // Tarefas não são apagadas — só soltas da campanha (podem continuar no board de tarefas soltas).
        DB::table('marketing_tasks')->where('campaign_id', $campaign)->update(['campaign_id' => null]);
        DB::table('marketing_campaigns')->where('id', $campaign)->delete();

        return back()->with('success', 'Campanha removida.');
    }

    public function attachProduct(Request $request, int $campaign)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'suggested_action' => ['nullable', 'string', 'max:30'],
            'discount_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
        ]);

        $productBelongs = DB::table('products')->where('id', $data['product_id'])->where('company_id', $companyId)->exists();
        if (!$productBelongs) {
            return back()->withErrors(['product_id' => 'Produto não encontrado.']);
        }

        DB::table('marketing_campaign_products')->updateOrInsert(
            ['campaign_id' => $campaign, 'product_id' => $data['product_id']],
            [
                'suggested_action' => $data['suggested_action'] ?? null,
                'discount_pct' => $data['discount_pct'] ?? null,
                'notes' => $data['notes'] ?? null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return back()->with('success', 'Produto vinculado à campanha.');
    }

    public function detachProduct(int $campaign, int $product)
    {
        $companyId = Auth::user()?->company_id;
        $exists = DB::table('marketing_campaigns')->where('id', $campaign)->where('company_id', $companyId)->exists();
        abort_unless($exists, 404);

        DB::table('marketing_campaign_products')->where('campaign_id', $campaign)->where('product_id', $product)->delete();

        return back()->with('success', 'Produto removido da campanha.');
    }

    /** Busca de produtos pra vincular à campanha (autocomplete). */
    public function searchProducts(Request $request)
    {
        $companyId = Auth::user()?->company_id;
        $term = trim((string) $request->get('q', ''));

        if (!$companyId || $term === '') {
            return response()->json(['products' => []]);
        }

        $products = DB::table('products')
            ->where('company_id', $companyId)
            ->where(function ($q) use ($term) {
                $q->where('title', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%");
            })
            ->select('id', 'sku', 'title', 'brand', 'sale_price')
            ->limit(20)
            ->get();

        return response()->json(['products' => $products]);
    }
}
