<?php

namespace App\Http\Controllers\Monitoring;

use App\Http\Controllers\Controller;
use App\Services\MarketOptimizerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

/**
 * Relatório de competitividade: distribuição por marca, nível de concorrência
 * e — o principal para a gestão — a lista de quem está ganhando ou perdendo
 * a Buy Box.
 */
class MonitoringReportController extends Controller
{
    public function __construct(private MarketOptimizerService $opt)
    {
    }

    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }
        $companyId = $user->company_id;

        $situacao = $request->query('situacao', 'perdendo');
        if (!in_array($situacao, ['ganhando', 'perdendo', 'sem_info'], true)) {
            $situacao = 'perdendo';
        }

        return Inertia::render('Monitoring/Report', array_merge(
            $this->opt->report($companyId),
            [
                'buybox' => $this->opt->buybox($companyId),
                'lista' => $this->opt->buyboxList($companyId, $situacao),
                'situacao' => $situacao,
            ]
        ));
    }

    /** Exporta a lista de Buy Box em CSV (abre direto no Excel). */
    public function export(Request $request)
    {
        $user = Auth::user();
        if (!$user || !$user->company_id) {
            return redirect()->route('login');
        }

        $situacao = $request->query('situacao', 'perdendo');
        if (!in_array($situacao, ['ganhando', 'perdendo', 'sem_info'], true)) {
            $situacao = 'perdendo';
        }

        $rows = $this->opt->buyboxList($user->company_id, $situacao, 5000);
        $file = 'buybox-' . $situacao . '-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM p/ acentos no Excel
            fputcsv($out, ['SKU', 'SKU Netshoes', 'Produto', 'Marca', 'Meu preço',
                'Preço mercado', 'Diferença', 'Gap %', 'Vendedor', 'Ofertas', 'Link'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['sku'], $r['netshoes_sku'], $r['titulo'], $r['marca'],
                    number_format((float) $r['preco'], 2, ',', ''),
                    $r['market_price'] !== null ? number_format((float) $r['market_price'], 2, ',', '') : '',
                    $r['diferenca'] !== null ? number_format((float) $r['diferenca'], 2, ',', '') : '',
                    $r['gap'], $r['seller'], $r['ofertas'], $r['url'],
                ], ';');
            }
            fclose($out);
        }, $file, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
