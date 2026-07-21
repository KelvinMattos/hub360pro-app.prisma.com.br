<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use App\Services\PromoCalculatorService;
use Inertia\Inertia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Central de Cálculo Promocional — Todos os Canais.
 *
 * Substitui a antiga planilha "CALCULO PROMO" (Excel), que se tornou
 * inutilizável pelo volume de PROCV/XLOOKUP. O motor de cálculo roda
 * inteiramente no navegador (Vue): o usuário importa os modelos que o
 * sistema já exporta (PV Atual, Custo, Centauro, Renner, Netshoes-Portal,
 * PV De-Por), o sistema calcula todas as margens por canal e exporta um
 * modelo de planilha de saída — sem processamento pesado no servidor.
 */
class CalculoPromoController extends Controller
{
    /**
     * Exibe a central de cálculo promocional.
     *
     * A configuração padrão de canais, faixas e regras é entregue como prop
     * para permitir, no futuro, persistência por empresa (Multi-Tenancy).
     */
    /**
     * Cálculo Promo direto do banco (sem upload), por canal, paginado.
     */
    public function index(Request $request, PromoCalculatorService $calc)
    {
        $companyId = Auth::user()?->company_id;
        $cfg = $calc->config($companyId);
        $data = $calc->compute($companyId, $cfg, (string) $request->query('channel', 'site'));

        $rows = collect($data['rows']);
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $s = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['sku']), $s) || str_contains(mb_strtolower((string) $r['produto']), $s))->values();
        }

        $total = $rows->count();
        $promosAbaixo = $rows->where('promo_menor', 'SIM')->count();
        $semCusto = $rows->filter(fn ($r) => $r['custo'] <= 0)->count();

        $perPage = 100;
        $page = max(1, (int) $request->query('page', 1));
        $lastPage = max(1, (int) ceil($total / $perPage));

        return Inertia::render('Pricing/CalculoPromo', [
            'channel' => $data['channel'],
            'channels' => $data['channels'],
            'globals' => $data['globals'],
            'rows' => $rows->slice(($page - 1) * $perPage, $perPage)->values()->all(),
            'stats' => ['total' => $total, 'promos_abaixo' => $promosAbaixo, 'sem_custo' => $semCusto],
            'filters' => ['q' => $search, 'channel' => $data['channel']['key']],
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage],
        ]);
    }

    /**
     * Exporta o cálculo do canal em CSV (todos os produtos, respeitando a busca).
     */
    public function export(Request $request, PromoCalculatorService $calc)
    {
        $companyId = Auth::user()?->company_id;
        $cfg = $calc->config($companyId);
        $data = $calc->compute($companyId, $cfg, (string) $request->query('channel', 'site'));

        $rows = collect($data['rows']);
        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $s = mb_strtolower($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtolower($r['sku']), $s) || str_contains(mb_strtolower((string) $r['produto']), $s));
        }

        $filename = 'calculo_promo_' . $data['channel']['key'] . '_' . now()->format('Ymd_His') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"$filename\"",
        ];

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, "\xEF\xBB\xBF"); // BOM UTF-8 (Excel)
            fputcsv($out, ['SKU', 'Produto', 'Marca', 'Estoque', 'Tempo Estoque', 'Custo', 'PV Atual', 'Ponto Equilíbrio', 'Meta Lucro', 'PV Promo Sugerido', 'Resultado Atual', 'Resultado Promo', '% Desc.', 'Promo < PV?'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['sku'], $r['produto'], $r['marca'], $r['estoque'], $r['tempo_estoque'],
                    $this->br($r['custo']), $this->br($r['pv_atual']), $this->br($r['ponto_equilibrio']),
                    $this->br($r['meta_lucro']), $this->br($r['promo_sugerido']), $this->br($r['resultado_atual']),
                    $this->br($r['resultado_promo']),
                    $r['perc_desc'] !== null ? number_format($r['perc_desc'] * 100, 1, ',', '') : '',
                    $r['promo_menor'] ?? '',
                ], ';');
            }
            fclose($out);
        }, $filename, $headers);
    }

    /** Número no padrão BR para CSV. */
    private function br($v): string
    {
        return $v === null ? '' : number_format((float) $v, 2, ',', '.');
    }

    /**
     * Parâmetros padrão do motor de cálculo (espelham a planilha original,
     * já com a correção do ponto de equilíbrio — imposto/MC/comissão em %).
     */
    public static function defaultConfig(): array
    {
        return [
            'imposto' => 8,   // % sobre o preço de venda
            'mc'      => 11,  // margem de contribuição %
            'descAtualDefault' => 20,   // desconto sobre PV atual %
            'descEquilDefault' => 10,   // desconto sobre ponto de equilíbrio %
            'rounding' => 0.90,         // terminação do preço (R$)
            'channels' => [
                ['id' => 'site',        'label' => 'Site / Loja',           'origem' => 'pvatual',  'col' => 'Site',          'comissao' => 2,  'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'ml_classico', 'label' => 'Mercado Livre Clássico', 'origem' => 'pvatual',  'col' => 'Mercado Livre', 'comissao' => 18, 'temFaixa' => 'ml',     'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'ml_premium',  'label' => 'Mercado Livre Premium',  'origem' => 'pvatual',  'col' => 'Mercado Livre', 'comissao' => 26, 'temFaixa' => 'ml',     'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'netshoes',    'label' => 'Netshoes',              'origem' => 'pvatual',  'col' => 'Netshoes',      'comissao' => 24, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'centauro',    'label' => 'Centauro',              'origem' => 'centauro', 'col' => null,            'comissao' => 20, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'renner',      'label' => 'Renner',                'origem' => 'renner',   'col' => null,            'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'shopee',      'label' => 'Shopee',                'origem' => 'pvatual',  'col' => 'Shopee',        'comissao' => 20, 'temFaixa' => 'shopee', 'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => 'Na planilha original a faixa de taxa fixa do Shopee tinha um bug (na faixa 80–99,99 reusava a taxa de R$ 4 no lugar de R$ 16). Corrigido aqui conforme a tabela TAXAS — confirme os valores.'],
                ['id' => 'amazon',      'label' => 'Amazon',                'origem' => 'pvatual',  'col' => 'Amazon',        'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'magalu',      'label' => 'Magalu',                'origem' => 'pvatual',  'col' => 'Magalu',        'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'dafiti',      'label' => 'Dafiti',                'origem' => 'pvatual',  'col' => 'Dafiti',        'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'via',         'label' => 'Via Varejo',            'origem' => 'pvatual',  'col' => 'Via Varejo',    'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => null],
                ['id' => 'shop_cop',    'label' => 'Shop Cop.',             'origem' => 'pvatual',  'col' => 'Amazon',        'comissao' => 13, 'temFaixa' => 'none',   'markup' => 23.433, 'descAtual' => 20, 'descEquil' => 10, 'warn' => 'Na planilha original esse canal apontava para uma coluna que não existe mais em PV ATUAL. Valor provisório — selecione a coluna correta.'],
            ],
        ];
    }
}
