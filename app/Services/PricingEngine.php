<?php

namespace App\Services;

/**
 * PricingEngine — fonte única de verdade de precificação.
 *
 * Todo módulo que precifica (Centro de Decisão, Calculadora de Canais, Cálculo
 * Promo e Repricing) deve consumir ESTE serviço. Antes cada tela tinha a sua
 * própria fórmula e elas divergiam — o caso mais grave era o piso do Repricing,
 * que usava `custo × (1 + margem)` e IGNORAVA os encargos do canal, permitindo
 * "piso" que na verdade dava prejuízo (ver `floorPrice()`).
 *
 * ------------------------------------------------------------------
 * CONVENÇÃO CENTRAL: encargos e margem são percentuais SOBRE O PREÇO DE VENDA,
 * não sobre o custo. Por isso a forma é `custo / (1 - taxas)` e não
 * `custo × (1 + taxas)`.
 *
 *   PV_equilíbrio = custo_total / (1 − encargos%)
 *   PV_meta       = custo_total / (1 − encargos% − margem_alvo%)
 *
 * Encargos = comissão do canal + imposto + taxa de anúncio/ads + qualquer
 * percentual proporcional ao preço. Custos em R$ (frete, taxa fixa, embalagem)
 * entram no `custo_total`, não no percentual.
 * ------------------------------------------------------------------
 */
class PricingEngine
{
    /**
     * Faixas de taxa fixa do Mercado Livre e da Shopee — validadas
     * célula a célula contra a planilha original (ver CLAUDE.md §9).
     * Antes desta refatoração existiam DUAS cópias idênticas destas faixas
     * (ManagementDecisionService e PromoCalculatorService); ambas agora
     * delegam para `tieredBreakEven()`.
     */
    private const ML_TIER = [
        ['max' => 12.5, 'mode' => 'half'], ['max' => 29, 'fee' => 6.25],
        ['max' => 50, 'fee' => 6.5], ['max' => 79, 'fee' => 6.75], ['max' => INF, 'fee' => 0],
    ];
    private const SHOPEE_TIER = [
        ['max' => 79.99, 'fee' => 4], ['max' => 99.99, 'fee' => 16],
        ['max' => 199.99, 'fee' => 20], ['max' => INF, 'fee' => 26],
    ];
    /**
     * Soma dos encargos percentuais (sobre o preço). Aceita tanto
     * ['comissao' => 14, 'imposto' => 8] quanto um float já somado.
     */
    public function chargesPct(array|float|int $charges): float
    {
        if (is_array($charges)) {
            $sum = 0.0;
            foreach ($charges as $v) {
                $sum += (float) $v;
            }
            return $sum;
        }
        return (float) $charges;
    }

    /**
     * Custo total em R$ por unidade: CMV + custos fixos em dinheiro
     * (frete pago pelo seller, taxa fixa, embalagem, etc.).
     */
    public function totalCost(float $cmv, array|float|int $fixedCosts = 0): float
    {
        $extra = 0.0;
        if (is_array($fixedCosts)) {
            foreach ($fixedCosts as $v) {
                $extra += (float) $v;
            }
        } else {
            $extra = (float) $fixedCosts;
        }
        return max(0.0, $cmv + $extra);
    }

    /**
     * PV de equilíbrio: preço em que a margem líquida é exatamente zero.
     *
     *   PV = custo_total / (1 − encargos%)
     *
     * Retorna null quando os encargos são >= 100% (não existe preço viável:
     * qualquer preço deixaria prejuízo). Nunca lança divisão por zero.
     */
    public function breakEven(float $totalCost, array|float|int $charges): ?float
    {
        $pct = $this->chargesPct($charges) / 100;
        $denom = 1 - $pct;

        if ($denom <= 0.0000001) {
            return null; // encargos consomem 100% ou mais do preço
        }
        return round($totalCost / $denom, 2);
    }

    /**
     * PV meta: preço que entrega a margem líquida alvo.
     *
     *   PV = custo_total / (1 − encargos% − margem_alvo%)
     *
     * Retorna null quando encargos + margem alvo >= 100% (meta impossível).
     */
    public function targetPrice(float $totalCost, array|float|int $charges, float $targetMarginPct): ?float
    {
        $pct = ($this->chargesPct($charges) + $targetMarginPct) / 100;
        $denom = 1 - $pct;

        if ($denom <= 0.0000001) {
            return null; // margem alvo inatingível com esses encargos
        }
        return round($totalCost / $denom, 2);
    }

    /**
     * PISO de venda: o menor preço aceitável.
     *
     * ⚠️ Correção importante: o Repricing usava `custo × (1 + margem)`, que
     * ignora os encargos do canal. Com comissão de 16% + imposto de 8%, um
     * "piso" assim ficava ABAIXO do ponto de equilíbrio — ou seja, autorizava
     * venda no prejuízo. O piso correto é o PV meta com a margem mínima
     * (que, com margem mínima 0, degenera exatamente no ponto de equilíbrio).
     */
    public function floorPrice(float $totalCost, array|float|int $charges, float $minMarginPct = 0): ?float
    {
        return $this->targetPrice($totalCost, $charges, $minMarginPct);
    }

    /**
     * Contribuição unitária em R$ para um preço praticado:
     *   preço − encargos(preço) − custo_total
     */
    public function unitContribution(float $price, float $totalCost, array|float|int $charges): float
    {
        $pct = $this->chargesPct($charges) / 100;
        return round($price - ($price * $pct) - $totalCost, 2);
    }

    /**
     * Margem líquida (%) sobre o preço praticado.
     * Guarda de divisão por zero: preço <= 0 devolve null (a UI mostra "—",
     * nunca "NaN%" nem 0% enganoso).
     */
    public function netMarginPct(float $price, float $totalCost, array|float|int $charges): ?float
    {
        if ($price <= 0) {
            return null;
        }
        return round($this->unitContribution($price, $totalCost, $charges) / $price * 100, 2);
    }

    /**
     * Modo legado (markup sobre o equilíbrio), preservado porque o Cálculo
     * Promo foi validado célula a célula contra a planilha original:
     *   PV = equilíbrio × (1 + markup%)
     *
     * NÃO é equivalente a `targetPrice()`: markup incide sobre o custo já
     * "brutado", enquanto a margem alvo incide sobre o preço. Use este método
     * apenas onde a paridade com a planilha for requisito.
     */
    public function targetPriceFromMarkup(float $totalCost, array|float|int $charges, float $markupPct): ?float
    {
        $be = $this->breakEven($totalCost, $charges);
        return $be === null ? null : round($be * (1 + $markupPct / 100), 2);
    }

    /**
     * Converte um markup (sobre o equilíbrio) na margem líquida equivalente,
     * para comparar os dois mundos:  margem% = markup / (1 + markup)
     */
    public function markupToMarginPct(float $markupPct): float
    {
        $m = $markupPct / 100;
        return round($m / (1 + $m) * 100, 4);
    }

    /**
     * Arredondamento comercial: leva o preço para terminar em `,90`
     * (ex.: 154,23 -> 153,90). Nunca sobe acima do valor pedido.
     */
    public function roundToCharm(float $price, float $ending = 0.90): float
    {
        if ($price <= 0) {
            return 0.0;
        }
        $floor = floor($price) + $ending;
        if ($floor > $price) {
            $floor -= 1;
        }
        return round(max(0.01, $floor), 2);
    }

    /**
     * Ponto de equilíbrio com faixa de taxa fixa (ML/Shopee) — mesma lógica
     * de duas passadas usada por Cálculo Promo e Centro de Decisão:
     *   1. Estima o equilíbrio só com os encargos percentuais.
     *   2. Escolhe a faixa de taxa fixa com base nessa estimativa.
     *   3. Recalcula somando a taxa fixa da faixa ao custo.
     *
     * `mode`: 'none' (sem faixa) | 'ml' (Mercado Livre) | 'shopee'.
     * Retorna null quando os encargos percentuais são >= 100% (mesma regra
     * de `breakEven()` — nunca devolve zero como se fosse uma resposta válida).
     */
    public function tieredBreakEven(float $cost, array|float|int $charges, string $mode = 'none'): ?float
    {
        $pct = $this->chargesPct($charges) / 100;
        $denom = 1 - $pct;
        if ($denom <= 0.0000001) {
            return null;
        }

        $base = $cost / $denom;

        if ($mode === 'ml') {
            if ($base < 12.5) {
                return round($base + $base / 2, 2);
            }
            foreach (self::ML_TIER as $t) {
                if (isset($t['fee']) && $base < $t['max']) {
                    return round(($cost + $t['fee']) / $denom, 2);
                }
            }
            return round($base, 2);
        }

        if ($mode === 'shopee') {
            foreach (self::SHOPEE_TIER as $t) {
                if ($base <= $t['max']) {
                    return round(($cost + $t['fee']) / $denom, 2);
                }
            }
            return round($base, 2);
        }

        return round($base, 2);
    }

    /**
     * PV Promo sugerido (Cálculo Promo) — validado célula a célula contra a
     * planilha original (CLAUDE.md §9):
     *
     *   MAX( ROUNDDOWN(PV_atual × (1 − descAtual%) − arred) + arred ;
     *        equilíbrio × (1 − descEquil%) )
     */
    public function suggestedPromoPrice(
        float $currentPrice,
        float $breakEven,
        float $currentDiscountPct,
        float $breakEvenDiscountPct,
        float $rounding = 0.90
    ): float {
        $p1 = floor($currentPrice * (1 - $currentDiscountPct / 100) - $rounding) + $rounding;
        $p2 = $breakEven * (1 - $breakEvenDiscountPct / 100);
        return max($p1, $p2);
    }

    /**
     * Diagnóstico completo de um preço — usado pelas telas para nunca
     * recalcularem nada por conta própria.
     *
     * @return array{break_even:?float,target:?float,floor:?float,contribution:float,margin_pct:?float,viable:bool}
     */
    public function analyze(
        float $price,
        float $cmv,
        array|float|int $charges,
        float $targetMarginPct = 0,
        float $minMarginPct = 0,
        array|float|int $fixedCosts = 0
    ): array {
        $totalCost = $this->totalCost($cmv, $fixedCosts);
        $be = $this->breakEven($totalCost, $charges);
        $floor = $this->floorPrice($totalCost, $charges, $minMarginPct);

        return [
            'total_cost' => round($totalCost, 2),
            'charges_pct' => $this->chargesPct($charges),
            'break_even' => $be,
            'target' => $this->targetPrice($totalCost, $charges, $targetMarginPct),
            'floor' => $floor,
            'contribution' => $this->unitContribution($price, $totalCost, $charges),
            'margin_pct' => $this->netMarginPct($price, $totalCost, $charges),
            'viable' => $floor !== null && $price >= $floor,
        ];
    }
}
