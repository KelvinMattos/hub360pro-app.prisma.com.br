<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Jobs\ImportMarketPricesJob;
use App\Services\Netshoes\BuyBoxSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;

/**
 * Importação de PREÇOS DE MERCADO (concorrência) por planilha — o feed do
 * módulo de monitoramento enquanto não há coleta automática (API/scraper).
 *
 * O processamento roda em fila (ImportMarketPricesJob), não mais dentro do
 * request: um arquivo de 41999 linhas era cortado pelo timeout de borda do
 * Cloudflare (~100s) e, como tudo ficava numa única transação, nada era
 * gravado — market_price nunca chegou a ser preenchido numa importação
 * grande. Ver App\Services\Monitoring\MarketPriceImportProcessor.
 *
 * Aceita .xlsx e .csv. Cruza pelo `sku` e, como o SKU Netshoes é universal
 * entre sellers, também pelo `netshoes_sku`. Grava market_price / market_seller
 * / market_source='import' / market_checked_at. Não cria produtos.
 */
class MarketPriceImportController extends Controller
{
    public function __construct(private BuyBoxSyncService $sync)
    {
    }

    public function form()
    {
        return Inertia::render('Monitoring/MarketImport', [
            'seller_name' => $this->sync->config(Auth::user()?->company_id)['netshoes_seller_name'] ?? '',
        ]);
    }

    public function progress(string $token)
    {
        $key = 'mkt_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        $data = Cache::store('file')->get($key) ?: ['status' => 'pending', 'done' => 0, 'total' => 0];
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:120000'],
        ]);

        $ext = strtolower((string) $request->file('file')->getClientOriginalExtension());
        if (!in_array($ext, ['xlsx', 'xls', 'csv', 'txt'], true)) {
            return redirect()->route('monitoring.market.form')
                ->with('error', 'Envie um arquivo .xlsx ou .csv (recebido: .' . ($ext ?: '?') . ').');
        }

        $companyId = Auth::user()->company_id;
        $isXlsx = in_array($ext, ['xlsx', 'xls'], true);

        $token = (string) $request->input('progress_token', '') ?: (string) Str::uuid();
        $key = 'mkt_import_' . preg_replace('/[^a-zA-Z0-9_-]/', '', $token);
        Cache::store('file')->put($key, ['status' => 'processing', 'done' => 0, 'total' => 0], now()->addMinutes(30));

        // Move o upload (arquivo temporário que some no fim do request) para um
        // local persistente — o job roda depois, quando esse tmp já não existe.
        $storedPath = $request->file('file')->store('private/market-imports');

        ImportMarketPricesJob::dispatch($companyId, $storedPath, $isXlsx, $token);

        return redirect()->route('monitoring.market.form')
            ->with('success', 'Importação enviada para processamento em segundo plano. Acompanhe o progresso na tela.')
            ->with('importResult', ['ok' => true, 'queued' => true, 'progress_token' => $token]);
    }
}
