<?php

namespace App\Http\Controllers\Pricing;

use App\Http\Controllers\Controller;
use App\Services\ChannelConfigService;
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
    public function index(Request $request, ChannelConfigService $config)
    {
        // Datas reais de lançamento (importadas do Magazord) para o cálculo do
        // tempo de estoque — substituem a heurística por prefixo de SKU quando existem.
        $launchedDates = \App\Models\Product::whereNotNull('launched_at')
            ->whereNotNull('sku')
            ->pluck('launched_at', 'sku')
            ->map(fn ($d) => $d?->format('Y-m-d'))
            ->filter();

        return Inertia::render('Pricing/CalculoPromo', [
            'defaults' => $config->forCompany(Auth::user()?->company_id),
            'launchedDates' => $launchedDates,
        ]);
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
