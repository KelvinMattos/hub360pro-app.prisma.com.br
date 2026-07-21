<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

/**
 * Centro de Decisão Gerencial — análise por canal.
 *
 * Consolida os dados reais de produto (custo, preço, promoção, estoque, data
 * de lançamento) e calcula margem de contribuição e ponto de equilíbrio no
 * canal escolhido (comissão e taxas fixas ML/Shopee específicas), priorizando
 * ações: prejuízo, promoção perigosa, liquidar, oportunidade e dados faltando.
 */
class ManagementDecisionService
{
    private const LIST_LIMIT = 80;

    private const ML_TIER = [
        ['max' => 12.5, 'mode' => 'half'], ['max' => 29, 'fee' => 6.25],
        ['max' => 50, 'fee' => 6.5], ['max' => 79, 'fee' => 6.75], ['max' => INF, 'fee' => 0],
    ];
    private const SHOPEE_TIER = [
        ['max' => 79.99, 'fee' => 4], ['max' => 99.99, 'fee' => 16],
        ['max' => 199.99, 'fee' => 20], ['max' => INF, 'fee' => 26],
    ];

    public function __construct(private ChannelConfigService $config)
    {
    }

    public function analyze(int $companyId, ?string $channelKey = null): array
    {
        $cfg = $this->config->forCompany($companyId);
        $imposto = (float) $cfg['imposto'];
        $mc = (float) $cfg['mc'];
        $channels = collect($cfg['channels'])->filter(fn ($c) => ($c['active'] ?? true))->values();

        $channel = $channels->firstWhere('id', $channelKey) ?? $channels->firstWhere('id', 'site') ?? $channels->first();
        $comissao = (float) ($channel['comissao'] ?? 0);
        $temFaixa = $channel['temFaixa'] ?? 'none';
        $encargosPct = $imposto + $mc + $comissao;

        $hasPromo = Schema::hasColumn('products', 'promotional_price');
        $hasLaunch = Schema::hasColumn('products', 'launched_at');

        $select = ['id', 'sku', 'title', 'stock_quantity', 'cost_price', 'sale_price'];
        if (Schema::hasColumn('products', 'brand')) $select[] = 'brand';
        if ($hasPromo) $select[] = 'promotional_price';
        if ($hasLaunch) $select[] = 'launched_at';

        $rows = DB::table('products')->where('company_id', $companyId)->select($select)->get();
        $now = Carbon::now();

        $totalSkus = 0; $skusComEstoque = 0;
        $capitalImobilizado = 0.0; $faturamentoPotencial = 0.0; $lucroPotencial = 0.0;
        $somaMargemPonderada = 0.0; $somaReceitaPonderada = 0.0;
        $capitalParadoAntigo = 0.0; $countDadosFaltando = 0;

        $prejuizo = []; $promoPerigosa = []; $liquidar = []; $oportunidade = []; $dadosFaltando = [];

        foreach ($rows as $p) {
            $totalSkus++;
            $stock = (int) ($p->stock_quantity ?? 0);
            $cost = (float) ($p->cost_price ?? 0);
            $price = (float) ($p->sale_price ?? 0);
            $promo = $hasPromo ? (float) ($p->promotional_price ?? 0) : 0.0;

            $ageMonths = null; $ageBucket = 'Sem data';
            if ($hasLaunch && !empty($p->launched_at)) {
                try {
                    $ageMonths = (int) abs(Carbon::parse($p->launched_at)->diffInMonths($now));
                    $ageBucket = $this->ageBucket($ageMonths);
                } catch (\Throwable $e) {
                    $ageMonths = null;
                }
            }

            $capital = $stock * $cost;
            $capitalImobilizado += $capital;
            if ($stock > 0) $skusComEstoque++;

            if ($cost <= 0 || $price <= 0) {
                $countDadosFaltando++;
                $dadosFaltando[] = $this->row($p, $stock, $cost, $price, null, null, $ageBucket, $capital, [
                    'motivo' => $cost <= 0 && $price <= 0 ? 'sem custo e sem preço' : ($cost <= 0 ? 'sem custo' : 'sem preço'),
                ]);
                continue;
            }

            $breakEven = $this->breakEven($cost, $imposto, $mc, $comissao, $temFaixa);
            $marginUnit = $this->marginUnit($price, $cost, $encargosPct);
            $marginPct = $price > 0 ? $marginUnit / $price * 100 : 0;

            $faturamentoPotencial += $stock * $price;
            $lucroPotencial += $stock * $marginUnit;
            $somaMargemPonderada += $marginUnit * max($stock, 1);
            $somaReceitaPonderada += $price * max($stock, 1);
            if ($ageMonths !== null && $ageMonths >= 12) $capitalParadoAntigo += $capital;

            $base = $this->row($p, $stock, $cost, $price, $breakEven, $marginPct, $ageBucket, $capital, [
                'margin_unit' => round($marginUnit, 2),
                'break_even' => round($breakEven, 2),
                'age_months' => $ageMonths,
            ]);

            if ($marginUnit < 0) {
                $prejuizo[] = $base + ['impacto' => abs($marginUnit) * max($stock, 1)];
            }
            if ($promo > 0 && $promo < $breakEven) {
                $promoMargin = $this->marginUnit($promo, $cost, $encargosPct);
                $promoPerigosa[] = $base + [
                    'promo' => round($promo, 2),
                    'promo_margin_unit' => round($promoMargin, 2),
                    'impacto' => abs($promoMargin) * max($stock, 1),
                ];
            }
            if ($ageMonths !== null && $ageMonths >= 12 && $stock > 0 && $capital > 0) {
                $liquidar[] = $base + ['impacto' => $capital];
            }
            if ($marginPct >= 30 && $ageMonths !== null && $ageMonths >= 6 && $stock > 0) {
                $oportunidade[] = $base + ['impacto' => $marginUnit * $stock];
            }
        }

        $margemMediaPct = $somaReceitaPonderada > 0 ? $somaMargemPonderada / $somaReceitaPonderada * 100 : 0;

        return [
            'globals' => [
                'imposto' => $imposto,
                'mc' => $mc,
                'has_promo' => $hasPromo,
                'has_launch' => $hasLaunch,
            ],
            'channel' => [
                'key' => $channel['id'] ?? 'site',
                'label' => $channel['label'] ?? 'Site / Loja',
                'comissao' => $comissao,
                'encargos_pct' => round($encargosPct, 2),
                'tem_faixa' => $temFaixa,
            ],
            'channels' => $channels->map(fn ($c) => ['key' => $c['id'], 'label' => $c['label']])->all(),
            'kpis' => [
                'total_skus' => $totalSkus,
                'skus_com_estoque' => $skusComEstoque,
                'capital_imobilizado' => round($capitalImobilizado, 2),
                'faturamento_potencial' => round($faturamentoPotencial, 2),
                'lucro_potencial' => round($lucroPotencial, 2),
                'margem_media_pct' => round($margemMediaPct, 1),
                'count_prejuizo' => count($prejuizo),
                'count_promo_perigosa' => count($promoPerigosa),
                'count_liquidar' => count($liquidar),
                'count_oportunidade' => count($oportunidade),
                'count_dados_faltando' => $countDadosFaltando,
                'capital_parado_antigo' => round($capitalParadoAntigo, 2),
            ],
            'alertas' => [
                'prejuizo' => $this->top($prejuizo),
                'promo_perigosa' => $this->top($promoPerigosa),
                'liquidar' => $this->top($liquidar),
                'oportunidade' => $this->top($oportunidade),
                'dados_faltando' => array_slice($dadosFaltando, 0, self::LIST_LIMIT),
            ],
        ];
    }

    private function breakEven(float $cost, float $imposto, float $mc, float $comissao, string $temFaixa): float
    {
        $aliq = 1 - ($imposto + $mc + $comissao) / 100;
        if ($aliq <= 0) return 0;
        $base = $cost / $aliq;
        if ($temFaixa === 'ml') {
            if ($base < 12.5) return $base + $base / 2;
            foreach (self::ML_TIER as $t) {
                if (isset($t['fee']) && $base < $t['max']) return ($cost + $t['fee']) / $aliq;
            }
            return $base;
        }
        if ($temFaixa === 'shopee') {
            foreach (self::SHOPEE_TIER as $t) {
                if ($base <= $t['max']) return ($cost + $t['fee']) / $aliq;
            }
            return $base;
        }
        return $base;
    }

    private function marginUnit(float $price, float $cost, float $encargosPct): float
    {
        return $price - $price * ($encargosPct / 100) - $cost;
    }

    private function ageBucket(int $months): string
    {
        return match (true) {
            $months < 6 => 'Menos de 6 meses',
            $months < 12 => 'Mais de 6 meses',
            $months < 18 => '1 ano',
            $months < 24 => '1 ano e meio',
            $months < 30 => '2 anos',
            default => '+2 anos',
        };
    }

    private function row($p, int $stock, float $cost, float $price, ?float $breakEven, ?float $marginPct, string $ageBucket, float $capital, array $extra): array
    {
        return array_merge([
            'sku' => $p->sku,
            'title' => $p->title,
            'brand' => $p->brand ?? null,
            'stock' => $stock,
            'cost' => round($cost, 2),
            'price' => round($price, 2),
            'margin_pct' => $marginPct !== null ? round($marginPct, 1) : null,
            'age_bucket' => $ageBucket,
            'capital' => round($capital, 2),
        ], $extra);
    }

    private function top(array $list): array
    {
        usort($list, fn ($a, $b) => ($b['impacto'] ?? 0) <=> ($a['impacto'] ?? 0));
        return array_slice($list, 0, self::LIST_LIMIT);
    }
}
