<?php

namespace App\Services;

use App\Http\Controllers\Pricing\CalculoPromoController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;

/**
 * Centro de Decisão Gerencial.
 *
 * Consolida os dados reais de produto (custo, preço de venda, preço promocional,
 * estoque e data de lançamento — todos alimentados pelas Importações Magazord)
 * e transforma em inteligência de decisão de precificação:
 *
 *   - Margem de contribuição e ponto de equilíbrio por produto
 *   - Produtos vendendo no prejuízo (corrigir preço)
 *   - Promoções abaixo do ponto de equilíbrio (risco)
 *   - Capital imobilizado em estoque antigo (liquidar)
 *   - Produtos lucrativos parados (oportunidade de promoção)
 *   - Produtos com dados faltando (custo/preço)
 *
 * Roda inteiramente sobre o banco (sem PROCV), com guards de coluna para
 * funcionar mesmo antes de rodar as migrations mais recentes.
 */
class ManagementDecisionService
{
    /** Limite de itens por lista acionável enviada ao frontend. */
    private const LIST_LIMIT = 80;

    public function analyze(int $companyId): array
    {
        $cfg = CalculoPromoController::defaultConfig();
        $imposto = (float) $cfg['imposto'];
        $mc = (float) $cfg['mc'];
        // Comissão do canal-base (loja/site) — o sale_price representa o preço do site.
        $siteCommission = (float) collect($cfg['channels'])->firstWhere('id', 'site')['comissao'];
        $encargosPct = $imposto + $mc + $siteCommission; // % sobre o preço de venda

        $hasPromo = Schema::hasColumn('products', 'promotional_price');
        $hasLaunch = Schema::hasColumn('products', 'launched_at');

        $select = ['id', 'sku', 'title', 'brand', 'stock_quantity', 'cost_price', 'sale_price'];
        if ($hasPromo) $select[] = 'promotional_price';
        if ($hasLaunch) $select[] = 'launched_at';

        $rows = DB::table('products')->where('company_id', $companyId)->select($select)->get();

        $now = Carbon::now();

        // Agregados
        $totalSkus = 0; $skusComEstoque = 0;
        $capitalImobilizado = 0.0; $faturamentoPotencial = 0.0; $lucroPotencial = 0.0;
        $somaMargemPonderada = 0.0; $somaReceitaPonderada = 0.0;
        $capitalParadoAntigo = 0.0; $countDadosFaltando = 0;

        // Listas acionáveis
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

            // Dados faltando (custo ou preço ausente)
            if ($cost <= 0 || $price <= 0) {
                $countDadosFaltando++;
                $dadosFaltando[] = $this->row($p, $stock, $cost, $price, $promo, null, null, $ageBucket, $capital, [
                    'motivo' => $cost <= 0 && $price <= 0 ? 'sem custo e sem preço' : ($cost <= 0 ? 'sem custo' : 'sem preço'),
                ]);
                continue; // sem custo/preço não dá para calcular margem confiável
            }

            $breakEven = $this->breakEven($cost, $encargosPct);
            $marginUnit = $this->marginUnit($price, $cost, $encargosPct);
            $marginPct = $price > 0 ? $marginUnit / $price * 100 : 0;

            $faturamentoPotencial += $stock * $price;
            $lucroPotencial += $stock * $marginUnit;
            $somaMargemPonderada += $marginUnit * max($stock, 1);
            $somaReceitaPonderada += $price * max($stock, 1);

            if ($ageMonths !== null && $ageMonths >= 12) {
                $capitalParadoAntigo += $capital;
            }

            $base = $this->row($p, $stock, $cost, $price, $promo, $breakEven, $marginPct, $ageBucket, $capital, [
                'margin_unit' => round($marginUnit, 2),
                'break_even' => round($breakEven, 2),
                'age_months' => $ageMonths,
            ]);

            // 1) Prejuízo — vende abaixo do custo+encargos
            if ($marginUnit < 0) {
                $prejuizo[] = $base + ['impacto' => abs($marginUnit) * max($stock, 1)];
            }

            // 2) Promoção perigosa — preço promocional abaixo do ponto de equilíbrio
            if ($promo > 0 && $promo < $breakEven) {
                $promoMargin = $this->marginUnit($promo, $cost, $encargosPct);
                $promoPerigosa[] = $base + [
                    'promo' => round($promo, 2),
                    'promo_margin_unit' => round($promoMargin, 2),
                    'impacto' => abs($promoMargin) * max($stock, 1),
                ];
            }

            // 3) Liquidar — estoque antigo (>= 1 ano) com capital relevante
            if ($ageMonths !== null && $ageMonths >= 12 && $stock > 0 && $capital > 0) {
                $liquidar[] = $base + ['impacto' => $capital];
            }

            // 4) Oportunidade — lucrativo (margem >= 30%) mas parado (>= 6 meses) com estoque
            if ($marginPct >= 30 && $ageMonths !== null && $ageMonths >= 6 && $stock > 0) {
                $oportunidade[] = $base + ['impacto' => $marginUnit * $stock];
            }
        }

        $margemMediaPct = $somaReceitaPonderada > 0 ? $somaMargemPonderada / $somaReceitaPonderada * 100 : 0;

        return [
            'globals' => [
                'imposto' => $imposto,
                'mc' => $mc,
                'site_commission' => $siteCommission,
                'encargos_pct' => round($encargosPct, 2),
                'has_promo' => $hasPromo,
                'has_launch' => $hasLaunch,
            ],
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

    private function breakEven(float $cost, float $encargosPct): float
    {
        $aliq = 1 - $encargosPct / 100;
        return $aliq > 0 ? $cost / $aliq : 0;
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

    private function row($p, int $stock, float $cost, float $price, float $promo, ?float $breakEven, ?float $marginPct, string $ageBucket, float $capital, array $extra): array
    {
        return array_merge([
            'sku' => $p->sku,
            'title' => $p->title,
            'brand' => $p->brand,
            'stock' => $stock,
            'cost' => round($cost, 2),
            'price' => round($price, 2),
            'margin_pct' => $marginPct !== null ? round($marginPct, 1) : null,
            'age_bucket' => $ageBucket,
            'capital' => round($capital, 2),
        ], $extra);
    }

    /** Ordena por impacto (desc) e limita. */
    private function top(array $list): array
    {
        usort($list, fn ($a, $b) => ($b['impacto'] ?? 0) <=> ($a['impacto'] ?? 0));
        return array_slice($list, 0, self::LIST_LIMIT);
    }
}
