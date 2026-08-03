<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\SalesChannelDailyImportService;
use App\Support\SalesChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Importação do relatório "Diário de Vendas" (uma aba por canal, uma linha
 * por dia) que o cliente mantinha manualmente em planilha — ver
 * App\Services\Sales\SalesChannelDailyImportService.
 *
 * Mesmo padrão de progresso via cache de arquivo + polling das demais
 * importações longas do sistema (Magazord, Netshoes, Notas Fiscais —
 * CLAUDE.md §6.3): o arquivo real é pequeno (poucas dezenas de KB), mas o
 * padrão é seguido por consistência e porque nada impede o cliente de
 * enviar um arquivo com mais abas/anos no futuro.
 */
class SalesChannelImportController extends Controller
{
    public function show()
    {
        return Inertia::render('SalesChannel/Import', [
            'channels' => collect(SalesChannels::LABELS)->map(fn ($label, $key) => [
                'key' => $key, 'label' => $label,
            ])->values(),
        ]);
    }

    public function import(Request $request, SalesChannelDailyImportService $service)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
        ]);

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            return redirect()->route('sales.channel-import.show')
                ->with('error', 'Envie o arquivo .xls ou .xlsx do Diário de Vendas (recebido: .' . ($ext ?: '?') . ').');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $companyId = Auth::user()->company_id;
        $path = $request->file('file')->getRealPath();
        $originalName = $request->file('file')->getClientOriginalName();

        $token = (string) $request->input('progress_token', '') ?: null;
        $key = $this->progressKey($token);

        $onProgress = $key ? function (int $done, int $total) use ($key) {
            try {
                Cache::store('file')->put($key, ['status' => 'processing', 'done' => $done, 'total' => $total], now()->addMinutes(30));
            } catch (\Throwable $e) {
                // progresso é best-effort; nunca derruba a importação
            }
        } : null;

        $result = $service->import($companyId, $path, $originalName, $onProgress);

        if ($key) {
            try {
                Cache::store('file')->put($key, [
                    'status' => 'done',
                    'done' => $result['sheets_total'] ?? 0,
                    'total' => $result['sheets_total'] ?? 0,
                    'result' => $result,
                ], now()->addMinutes(30));
            } catch (\Throwable $e) {
                // idem
            }
        }

        return redirect()->route('sales.channel-import.show')
            ->with('importResult', $result)
            ->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Endpoint de polling do progresso da importação (sem cache, JSON). */
    public function progress(string $token)
    {
        $data = Cache::store('file')->get($this->progressKey($token)) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function progressKey(?string $token): ?string
    {
        return $token ? 'sales_ch_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token) : null;
    }
}
