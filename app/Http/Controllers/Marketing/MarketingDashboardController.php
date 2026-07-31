<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingOpportunityService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Home do módulo de Marketing: oportunidades (lançamento/mais vendido/liquidar),
 * próximas datas comerciais, resumo do Kanban de campanhas e tarefas do usuário.
 */
class MarketingDashboardController extends Controller
{
    public function index(MarketingOpportunityService $opportunities)
    {
        $companyId = Auth::user()?->company_id;
        $userId = Auth::id();

        if (!$companyId) {
            return Inertia::render('Marketing/Dashboard', $this->emptyPayload());
        }

        $stageCounts = [];
        if (Schema::hasTable('marketing_campaigns')) {
            $counts = DB::table('marketing_campaigns')
                ->where('company_id', $companyId)
                ->select('stage', DB::raw('count(*) as c'))
                ->groupBy('stage')
                ->pluck('c', 'stage');
            foreach (['ideia', 'planejamento', 'execucao', 'revisao', 'concluido'] as $stage) {
                $stageCounts[$stage] = (int) ($counts[$stage] ?? 0);
            }
        }

        $upcomingDates = [];
        if (Schema::hasTable('commercial_dates')) {
            $now = Carbon::now()->startOfDay();
            // Tabela pequena (calendário, não catálogo) — traz tudo e resolve a
            // "próxima ocorrência" das datas recorrentes em PHP, mais simples e
            // seguro que tentar expressar isso em SQL.
            $upcomingDates = DB::table('commercial_dates')
                ->where(function ($q) use ($companyId) {
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->get()
                ->map(function ($d) use ($now) {
                    $date = Carbon::parse($d->date);
                    if ($d->recurring_yearly) {
                        $thisYear = $date->copy()->year($now->year);
                        $date = $thisYear->lt($now) ? $thisYear->addYear() : $thisYear;
                    }
                    return [
                        'id' => $d->id, 'title' => $d->title, 'category' => $d->category,
                        'date' => $date->toDateString(), 'days_until' => (int) $now->diffInDays($date, false),
                    ];
                })
                ->filter(fn ($d) => $d['days_until'] >= 0 && $d['days_until'] <= 120)
                ->sortBy('days_until')
                ->take(12)
                ->values()
                ->all();
        }

        $myTasks = [];
        $overdueCount = 0;
        if (Schema::hasTable('marketing_tasks')) {
            $today = Carbon::now()->toDateString();
            $myTasks = DB::table('marketing_tasks')
                ->where('company_id', $companyId)
                ->where('assignee_id', $userId)
                ->where('status', '!=', 'done')
                ->orderByRaw('due_date IS NULL, due_date')
                ->limit(10)
                ->get(['id', 'title', 'status', 'priority', 'due_date', 'campaign_id'])
                ->all();

            $overdueCount = DB::table('marketing_tasks')
                ->where('company_id', $companyId)
                ->where('status', '!=', 'done')
                ->whereNotNull('due_date')
                ->where('due_date', '<', $today)
                ->count();
        }

        return Inertia::render('Marketing/Dashboard', [
            'opportunities' => $opportunities->opportunities($companyId, 8),
            'upcomingDates' => $upcomingDates,
            'stageCounts' => $stageCounts,
            'myTasks' => $myTasks,
            'overdueCount' => $overdueCount,
        ]);
    }

    private function emptyPayload(): array
    {
        return [
            'opportunities' => ['lancamento' => [], 'mais_vendido' => [], 'liquidar' => [], 'perdendo_buybox' => []],
            'upcomingDates' => [],
            'stageCounts' => [],
            'myTasks' => [],
            'overdueCount' => 0,
        ];
    }
}
