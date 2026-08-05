<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\AmazonSalesDashboardImportService;
use App\Services\Sales\SalesChannelDailyImportService;
use App\Support\SalesChannels;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;

/**
 * Importação de vendas diárias por canal pra fora dos importadores nativos
 * de pedido (Mercado Livre/Shopee/Centauro/Renner/Magalu/Netshoes) — hoje
 * aceita dois formatos, detectados pelo CONTEÚDO do arquivo (nunca pela
 * extensão, CLAUDE.md §2.4):
 *
 * 1. "Diário de Vendas" (.xls/.xlsx, uma aba por canal, uma linha por dia)
 *    que o cliente mantinha manualmente — ver SalesChannelDailyImportService.
 * 2. Painel de Vendas da Amazon (.csv, Business Reports → Sales Dashboard
 *    do Seller Central) — ver AmazonSalesDashboardImportService. Adicionado
 *    05/08/2026 depois que o primeiro arquivo enviado pro canal Amazon
 *    (`vendasamazon.txt`) se revelou, na prática, um relatório de
 *    Listagens/Catálogo, não de vendas — este .csv é o formato real.
 *
 * Mesmo padrão de progresso via cache de arquivo + polling das demais
 * importações longas do sistema (Magazord, Netshoes, Notas Fiscais —
 * CLAUDE.md §6.3): os arquivos reais são pequenos (poucas dezenas/centenas
 * de KB), mas o padrão é seguido por consistência e porque nada impede o
 * cliente de enviar um arquivo com mais abas/anos no futuro.
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

    public function import(Request $request, SalesChannelDailyImportService $service, AmazonSalesDashboardImportService $amazonService)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
        ]);

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        $path = $request->file('file')->getRealPath();
        $originalName = $request->file('file')->getClientOriginalName();
        $isAmazonDashboard = $ext === 'csv' && $this->firstLineLooksLikeAmazonDashboard($path);

        if (!in_array($ext, ['xls', 'xlsx'], true) && !$isAmazonDashboard) {
            return redirect()->route('sales.channel-import.show')
                ->with('error', 'Formato não reconhecido (recebido: .' . ($ext ?: '?') . '). Envie o .xls/.xlsx do Diário de Vendas ou o .csv do Painel de Vendas (Seller Central) da Amazon.');
        }

        @set_time_limit(0);
        @ignore_user_abort(true);

        $companyId = Auth::user()->company_id;

        $token = (string) $request->input('progress_token', '') ?: null;
        $key = $this->progressKey($token);

        if ($isAmazonDashboard) {
            $result = $amazonService->import($companyId, $path, $originalName);
            if ($key) {
                try {
                    Cache::store('file')->put($key, [
                        'status' => 'done', 'done' => 1, 'total' => 1, 'result' => $result,
                    ], now()->addMinutes(30));
                } catch (\Throwable $e) {
                    // idem
                }
            }

            return redirect()->route('sales.channel-import.show')
                ->with('importResult', $result)
                ->with($result['ok'] ? 'success' : 'error', $result['message']);
        }

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

    /** Lê só a primeira linha do .csv (com BOM) pra decidir se é o Painel de Vendas da Amazon, sem carregar o arquivo inteiro. */
    private function firstLineLooksLikeAmazonDashboard(string $path): bool
    {
        $fh = @fopen($path, 'r');
        if ($fh === false) {
            return false;
        }

        $bom = fread($fh, 3);
        if ($bom !== "\xEF\xBB\xBF") {
            rewind($fh);
        }
        $firstLine = fgets($fh);
        fclose($fh);

        return $firstLine !== false && AmazonSalesDashboardImportService::looksLikeThisFormat($firstLine);
    }
}
