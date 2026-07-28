<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Netshoes\BuyBoxSyncService;
use App\Services\RepricingEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Repricing automático — desativado por padrão, dry-run por padrão.
 * Toda aplicação gera lote auditável e reversível.
 */
class RepricingController extends Controller
{
    public function __construct(
        private RepricingEngine $engine,
        private BuyBoxSyncService $sync
    ) {
    }

    private function settings(int $companyId): array
    {
        $cfg = $this->sync->config($companyId);
        return array_merge(RepricingEngine::DEFAULTS, array_intersect_key(
            $cfg, RepricingEngine::DEFAULTS
        ));
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $cfg = $this->settings($companyId);
        $plan = $this->engine->plan($companyId, $cfg);

        $marcas = [];
        if (Schema::hasColumn('products', 'brand')) {
            $q = DB::table('products')->whereNotNull('brand')->where('brand', '!=', '');
            if (Schema::hasColumn('products', 'company_id')) {
                $q->where('company_id', $companyId);
            }
            $marcas = $q->distinct()->orderBy('brand')->limit(300)->pluck('brand')->all();
        }

        return Inertia::render('Monitoring/Repricing', [
            'config' => $cfg,
            'plan' => array_slice($plan['items'], 0, 400),
            'stats' => $plan['stats'],
            'batches' => $this->engine->batches($companyId),
            'brand_margins' => $this->engine->brandMargins($companyId),
            'marcas' => $marcas,
        ]);
    }

    public function saveConfig(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'repricing_enabled' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'min_margin' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'max_change_pct' => ['nullable', 'numeric', 'min:0.1', 'max:100'],
            'max_age_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'undercut' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'only_losing' => ['nullable', 'boolean'],
        ]);

        $this->sync->saveConfig($user->company_id, $data);

        return back()->with('success', 'Configuração de repricing salva.');
    }

    public function saveBrandMargin(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'brand' => ['required', 'string', 'max:120'],
            'min_margin_pct' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $this->engine->saveBrandMargin($user->company_id, $data['brand'], (float) $data['min_margin_pct']);

        return back()->with('success', "Margem mínima da marca {$data['brand']} salva.");
    }

    public function apply(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $cfg = $this->settings($user->company_id);
        // Segurança: só sai do dry-run se o usuário pedir explicitamente.
        $cfg['dry_run'] = !$request->boolean('confirm_real');

        $res = $this->engine->apply($user->company_id, $user->id, $cfg);

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }

    public function rollback(Request $request, int $batch)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $res = $this->engine->rollback($user->company_id, $batch);

        return back()->with($res['ok'] ? 'success' : 'error', $res['message']);
    }
}
