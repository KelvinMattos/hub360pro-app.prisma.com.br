<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingOpportunityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
            return back()->with('error', 'Empresa não identificada.');
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
            return back()->with('error', 'Empresa não identificada.');
        }

        $data = $request->validate([
            'opportunity' => ['required', 'string', 'in:lancamento,mais_vendido,liquidar,perdendo_buybox'],
            'name' => ['required', 'string', 'max:255'],
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer'],
        ]);

        $typeMap = ['lancamento' => 'lancamento', 'mais_vendido' => 'outro', 'liquidar' => 'liquidacao', 'perdendo_buybox' => 'outro'];
        $actionMap = ['lancamento' => 'destacar', 'mais_vendido' => 'anunciar', 'liquidar' => 'liquidar', 'perdendo_buybox' => 'recuperar'];

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

    /**
     * Cria uma campanha "kit" pra uma data comercial: mais vendidos (empurrar
     * tráfego na data) + produtos parados (liquidar antes dela), já vinculados.
     * É a ponte entre o calendário e as oportunidades que o cliente pediu.
     */
    public function createFromDate(Request $request, MarketingOpportunityService $opportunities, int $date)
    {
        $companyId = Auth::user()?->company_id;
        if (!$companyId) {
            return back()->with('error', 'Empresa não identificada.');
        }

        $commercialDate = DB::table('commercial_dates')
            ->where('id', $date)
            ->where(function ($q) use ($companyId) {
                $q->whereNull('company_id')->orWhere('company_id', $companyId);
            })
            ->first();
        abort_unless($commercialDate, 404);

        $audience = Schema::hasColumn('commercial_dates', 'audience') ? $commercialDate->audience : null;

        // Busca um pool maior que o necessário porque, quando a data tem
        // público-alvo (Mães/Pais), parte dos melhores candidatos por venda
        // pode ser do gênero errado pra essa data e precisa ser descartada.
        $bestSellers = collect($opportunities->bestSellers($companyId, 20))
            ->reject(fn ($p) => $this->excludedByAudience($p['title'] ?? '', $audience))
            ->take(5);
        $liquidation = collect($opportunities->liquidationCandidates($companyId, 20))
            ->reject(fn ($p) => $this->excludedByAudience($p['title'] ?? '', $audience))
            ->take(5);

        if ($bestSellers->isEmpty() && $liquidation->isEmpty()) {
            return back()->with('error', 'Sem produtos elegíveis (mais vendidos ou parados) pra sugerir campanha nesta data ainda.');
        }

        $eventDate = Carbon::parse($commercialDate->date);
        if ($commercialDate->recurring_yearly) {
            $thisYear = $eventDate->copy()->year(Carbon::now()->year);
            $eventDate = $thisYear->lt(Carbon::now()->startOfDay()) ? $thisYear->addYear() : $thisYear;
        }

        $campaignId = DB::table('marketing_campaigns')->insertGetId([
            'company_id' => $companyId,
            'name' => 'Campanha — ' . $commercialDate->title,
            'type' => 'sazonal',
            'stage' => 'ideia',
            'source_opportunity' => 'calendario',
            'start_date' => Carbon::now()->toDateString(),
            'end_date' => $eventDate->toDateString(),
            'created_by' => Auth::id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Liquidação tem prioridade quando um produto aparece nas duas listas
        // (raro, mas possível): liberar capital parado é mais urgente do que
        // reforçar mídia num produto que já vende bem.
        $rows = [];
        $seen = [];
        foreach ($liquidation as $p) {
            if (isset($seen[$p['product_id']])) {
                continue;
            }
            $seen[$p['product_id']] = true;
            $rows[] = ['campaign_id' => $campaignId, 'product_id' => $p['product_id'], 'suggested_action' => 'liquidar', 'created_at' => now(), 'updated_at' => now()];
        }
        foreach ($bestSellers as $p) {
            if (isset($seen[$p['product_id']])) {
                continue;
            }
            $seen[$p['product_id']] = true;
            $rows[] = ['campaign_id' => $campaignId, 'product_id' => $p['product_id'], 'suggested_action' => 'anunciar', 'created_at' => now(), 'updated_at' => now()];
        }

        if (!empty($rows)) {
            DB::table('marketing_campaign_products')->insert($rows);
        }

        return redirect()->route('marketing.campaigns.show', $campaignId)
            ->with('success', 'Campanha criada para ' . $commercialDate->title . ' com ' . count($rows) . ' produto(s) sugerido(s).');
    }

    /**
     * Incidente 1: o kit sugerido pro Dia dos Pais recomendou produto "Feminino"
     * — o motor de oportunidades não tem noção de público, só de venda/estoque.
     * Incidente 2: o kit do Dia das Crianças trazia produtos masculino/feminino
     * de adulto — não basta excluir o gênero oposto, público infantil precisa
     * de regra própria.
     *
     * Duas estratégias, por não serem o mesmo tipo de erro:
     *  - masculino/feminino (Pais/Mães): modo EXCLUSÃO — descarta o que bate
     *    com gênero oposto adulto OU com público infantil (criança não é
     *    presente típico de Dia dos Pais/Mães). Não exige a palavra do gênero
     *    certo no título (a maioria dos produtos não fala "masculino"
     *    explicitamente, e exigir isso esvaziaria a lista à toa).
     *  - infantil (Crianças): modo INCLUSÃO — exige algum marcador
     *    infantil/juvenil no título. Sem isso, item adulto genérico (curva A
     *    ou parado) não tem por que entrar no kit dessa data.
     *
     * Não é classificação real de produto — é correspondência de palavra no
     * título, o mesmo dado que já expôs o erro original.
     */
    private const AUDIENCE_RULES = [
        'masculino' => ['mode' => 'exclude', 'patterns' => ['feminin', 'menin[ao]', '\bwoman\b', '\bwomens?\b', '\bgirls?\b', 'infantil', 'juvenil', 'crianc', '\bkids?\b', 'bebe']],
        'feminino' => ['mode' => 'exclude', 'patterns' => ['masculin', 'menin[ao]', '\bman\b', '\bmens?\b', '\bboys?\b', 'infantil', 'juvenil', 'crianc', '\bkids?\b', 'bebe']],
        'infantil' => ['mode' => 'require', 'patterns' => ['infantil', 'juvenil', 'crianc', '\bkids?\b', 'menin[ao]', 'bebe']],
    ];

    private function excludedByAudience(string $title, ?string $audience): bool
    {
        $rule = self::AUDIENCE_RULES[$audience] ?? null;
        if (!$rule) {
            return false;
        }

        // ascii() tira acento (criança -> crianca, bebê -> bebe) pra não
        // depender de o import ter vindo com encoding/acentuação consistente.
        $normalized = Str::of($title)->lower()->ascii()->toString();

        $matchesAny = false;
        foreach ($rule['patterns'] as $pattern) {
            if (preg_match('/' . $pattern . '/u', $normalized)) {
                $matchesAny = true;
                break;
            }
        }

        return $rule['mode'] === 'exclude' ? $matchesAny : !$matchesAny;
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

        // Nunca back() aqui: a página de origem é o Show desta própria campanha,
        // que deixou de existir — back() levaria a um 404 na campanha recém-apagada.
        return redirect()->route('marketing.campaigns.index')->with('success', 'Campanha removida.');
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
            return back()->with('error', 'Produto não encontrado.');
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
