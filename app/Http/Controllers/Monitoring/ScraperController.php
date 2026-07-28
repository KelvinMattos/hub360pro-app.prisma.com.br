<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\Netshoes\BuyBoxSyncService;
use App\Services\Netshoes\NetshoesBuyBoxScraper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

/**
 * Coleta de Buy Box na Netshoes: configuração, execução em lote (com
 * progresso ao vivo) e diagnóstico de 1 SKU.
 *
 * O lote roda de forma síncrona com progresso em cache — mesmo padrão das
 * importações — para funcionar em cPanel sem depender de worker de fila.
 */
class ScraperController extends Controller
{
    public function __construct(
        private BuyBoxSyncService $sync,
        private NetshoesBuyBoxScraper $scraper
    ) {
    }

    public function index()
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $elegiveis = 0;
        $coletados = 0;
        if (Schema::hasColumn('products', 'netshoes_sku')) {
            $q = DB::table('products')->whereNotNull('netshoes_sku')->where('netshoes_sku', '!=', '');
            if (Schema::hasColumn('products', 'company_id')) {
                $q->where('company_id', $companyId);
            }
            $elegiveis = (int) (clone $q)->count();
            if (Schema::hasColumn('products', 'market_checked_at')) {
                $coletados = (int) (clone $q)->whereNotNull('market_checked_at')->count();
            }
        }

        $erros = [];
        if (Schema::hasColumn('products', 'market_error')) {
            $eq = DB::table('products')->whereNotNull('market_error');
            if (Schema::hasColumn('products', 'company_id')) {
                $eq->where('company_id', $companyId);
            }
            $erros = $eq->selectRaw('market_error as erro, COUNT(*) as c')
                ->groupBy('market_error')->orderByDesc('c')->limit(8)->get()
                ->map(fn ($r) => ['erro' => $r->erro, 'total' => (int) $r->c])->all();
        }

        return Inertia::render('Monitoring/Scraper', [
            'config' => $this->sync->config($companyId),
            'stats' => ['elegiveis' => $elegiveis, 'coletados' => $coletados],
            'erros' => $erros,
        ]);
    }

    public function saveConfig(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $data = $request->validate([
            'scraper_enabled' => ['nullable', 'boolean'],
            'netshoes_seller_name' => ['nullable', 'string', 'max:120'],
            'search_url' => ['nullable', 'string', 'max:400'],
            'timeout' => ['nullable', 'integer', 'min:5', 'max:60'],
            'delay_ms' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'batch_limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'recheck_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
        ]);

        $this->sync->saveConfig($user->company_id, array_filter($data, fn ($v) => $v !== null));

        return back()->with('success', 'Configuração salva.');
    }

    /** Diagnóstico: testa 1 SKU e mostra exatamente o que foi capturado. */
    public function test(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return response()->json(['error' => 'unauthenticated'], 401);
        }

        $sku = trim((string) $request->input('sku', ''));
        if ($sku === '') {
            return response()->json(['ok' => false, 'error' => 'Informe um SKU Netshoes.']);
        }

        $cfg = $this->sync->config($user->company_id);
        $r = $this->scraper->fetch($sku, [
            'search_url' => $cfg['search_url'] ?? null,
            'timeout' => $cfg['timeout'] ?? null,
        ]);

        $our = NetshoesBuyBoxScraper::normalizeSeller($cfg['netshoes_seller_name'] ?? '');
        $r['buybox_winner'] = ($our !== '' && !empty($r['seller']))
            ? (NetshoesBuyBoxScraper::normalizeSeller($r['seller']) === $our)
            : null;

        return response()->json($r)->header('Cache-Control', 'no-store');
    }

    /** Executa uma rodada de coleta com progresso ao vivo. */
    public function run(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $companyId = $user->company_id;
        $token = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->input('progress_token', ''));
        $key = $token ? 'bb_scrape_' . $token : null;

        $cfg = $this->sync->config($companyId);
        $limit = (int) $request->input('batch_limit', $cfg['batch_limit'] ?? 200);
        $force = (bool) $request->boolean('force');

        $total = $this->sync->pending($companyId, $limit, (int) ($cfg['recheck_hours'] ?? 12), !$force)->count();
        $write = function (string $status, array $stats, array $extra = []) use ($key) {
            if (!$key) {
                return;
            }
            try {
                Cache::store('file')->put($key, array_merge([
                    'status' => $status,
                    'done' => ($stats['ok'] ?? 0) + ($stats['fail'] ?? 0),
                    'total' => $stats['total'] ?? 0,
                    'ok' => $stats['ok'] ?? 0,
                    'fail' => $stats['fail'] ?? 0,
                    'blocked' => $stats['blocked'] ?? 0,
                    'winning' => $stats['winning'] ?? 0,
                    'losing' => $stats['losing'] ?? 0,
                ], $extra), now()->addMinutes(60));
            } catch (\Throwable $e) {
            }
        };

        $write('processing', ['total' => $total, 'ok' => 0, 'fail' => 0, 'blocked' => 0, 'winning' => 0, 'losing' => 0]);

        $stats = $this->sync->run(
            $companyId,
            ['batch_limit' => $limit, 'force' => $force],
            fn ($s) => $write('processing', $s)
        );

        // Rodada abortada (desligado / sem SKU / bloqueio) é FALHA explícita,
        // nunca "concluída com sucesso".
        if (!empty($stats['aborted'])) {
            $summary = [
                'ok' => false,
                'rows' => $stats['total'], 'updated' => $stats['ok'], 'created' => 0,
                'skipped' => $stats['fail'],
                'message' => $stats['reason'] ?: 'Coleta interrompida.',
            ];
            $write('done', $stats, ['result' => $summary]);
            return redirect()->route('monitoring.scraper')
                ->with('importResult', $summary)->with('error', $summary['message']);
        }

        $summary = [
            'ok' => true,
            'rows' => $stats['total'],
            'updated' => $stats['ok'],
            'created' => 0,
            'skipped' => $stats['fail'],
            'message' => "Coleta concluída: {$stats['ok']} produtos atualizados, {$stats['fail']} falhas"
                . ($stats['blocked'] > 0 ? " ({$stats['blocked']} bloqueadas pelo site)" : '')
                . ($stats['winning'] + $stats['losing'] > 0
                    ? " · Buy Box: {$stats['winning']} ganhando, {$stats['losing']} perdendo." : '.'),
        ];
        $write('done', $stats, ['result' => $summary]);

        return redirect()->route('monitoring.scraper')
            ->with('importResult', $summary)->with('success', $summary['message']);
    }

    public function progress(string $token)
    {
        $key = 'bb_scrape_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];
        return response()->json($data)->header('Cache-Control', 'no-store');
    }
}
