<?php

namespace Tests\Unit;

use App\Services\PricingEngine;
use PHPUnit\Framework\TestCase;

class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new PricingEngine();
    }

    /* ---------------------------- breakEven ---------------------------- */

    public function test_break_even_matches_formula(): void
    {
        // custo=100, encargos=20% => 100 / (1 - 0.20) = 125
        $this->assertSame(125.0, $this->engine->breakEven(100, 20));
    }

    public function test_break_even_accepts_array_of_charges(): void
    {
        // comissão 14 + imposto 8 = 22% => 100 / 0.78 = 128.2051...
        $this->assertSame(128.21, $this->engine->breakEven(100, ['comissao' => 14, 'imposto' => 8]));
    }

    public function test_break_even_returns_null_when_charges_are_100_percent(): void
    {
        $this->assertNull($this->engine->breakEven(100, 100));
    }

    public function test_break_even_returns_null_when_charges_exceed_100_percent(): void
    {
        $this->assertNull($this->engine->breakEven(100, 130));
    }

    public function test_break_even_with_zero_charges_equals_cost(): void
    {
        $this->assertSame(100.0, $this->engine->breakEven(100, 0));
    }

    /* --------------------------- targetPrice ---------------------------- */

    public function test_target_price_matches_formula(): void
    {
        // custo=100, encargos=20%, margem alvo=10% => 100 / (1 - 0.30) = 142.857...
        $this->assertSame(142.86, $this->engine->targetPrice(100, 20, 10));
    }

    public function test_target_price_returns_null_when_impossible(): void
    {
        $this->assertNull($this->engine->targetPrice(100, 60, 40));
    }

    public function test_target_price_with_zero_margin_equals_break_even(): void
    {
        $this->assertSame(
            $this->engine->breakEven(200, 25),
            $this->engine->targetPrice(200, 25, 0)
        );
    }

    /* ---------------------------- floorPrice ----------------------------
     * Correção central desta refatoração: o piso do Repricing usava
     * custo × (1 + margem), ignorando encargos do canal — permitindo "piso"
     * abaixo do ponto de equilíbrio (prejuízo). O piso correto usa a mesma
     * fórmula do targetPrice.
     * -------------------------------------------------------------------- */

    public function test_floor_price_is_never_below_break_even_with_positive_margin(): void
    {
        $cost = 100.0;
        $charges = 22.0; // comissão + imposto do canal

        $floor = $this->engine->floorPrice($cost, $charges, 5); // margem mínima 5%
        $breakEven = $this->engine->breakEven($cost, $charges);

        $this->assertGreaterThan($breakEven, $floor);
    }

    public function test_floor_price_with_zero_min_margin_equals_break_even(): void
    {
        $this->assertSame(
            $this->engine->breakEven(150, 18),
            $this->engine->floorPrice(150, 18, 0)
        );
    }

    public function test_old_buggy_floor_formula_could_authorize_loss(): void
    {
        // Documenta o bug corrigido: custo x (1 + margem) ignorando 22% de
        // encargos do canal ficava ABAIXO do ponto de equilíbrio real —
        // ou seja, "autorizava" venda no prejuízo.
        $cost = 100.0;
        $channelCharges = 22.0;
        $minMargin = 10.0;

        $buggyFloor = $cost * (1 + $minMargin / 100); // fórmula antiga: 110.00
        $correctFloor = $this->engine->floorPrice($cost, $channelCharges, $minMargin);
        $breakEven = $this->engine->breakEven($cost, $channelCharges);

        $this->assertLessThan($breakEven, $buggyFloor, 'a fórmula antiga ficava abaixo do equilíbrio');
        $this->assertGreaterThanOrEqual($breakEven, $correctFloor, 'a fórmula corrigida nunca fica abaixo do equilíbrio');
    }

    /* ------------------------- unitContribution -------------------------- */

    public function test_unit_contribution_at_break_even_is_zero(): void
    {
        $cost = 100.0;
        $charges = 20.0;
        $price = $this->engine->breakEven($cost, $charges);

        $this->assertEqualsWithDelta(0.0, $this->engine->unitContribution($price, $cost, $charges), 0.01);
    }

    public function test_unit_contribution_positive_above_break_even(): void
    {
        $contribution = $this->engine->unitContribution(200, 100, 20);
        // 200 - (200*0.20) - 100 = 60
        $this->assertSame(60.0, $contribution);
    }

    /* --------------------------- netMarginPct ---------------------------- */

    public function test_net_margin_pct_guards_against_division_by_zero(): void
    {
        $this->assertNull($this->engine->netMarginPct(0, 100, 20));
        $this->assertNull($this->engine->netMarginPct(-10, 100, 20));
    }

    public function test_net_margin_pct_matches_expected_percentage(): void
    {
        // preço=200, custo=100, encargos=20% => contrib=60 => 60/200*100=30%
        $this->assertSame(30.0, $this->engine->netMarginPct(200, 100, 20));
    }

    /* ------------------------------ totalCost ------------------------------ */

    public function test_total_cost_sums_cmv_and_fixed_costs(): void
    {
        $this->assertSame(115.0, $this->engine->totalCost(100, ['frete' => 10, 'embalagem' => 5]));
        $this->assertSame(110.0, $this->engine->totalCost(100, 10));
    }

    public function test_total_cost_never_negative(): void
    {
        $this->assertSame(0.0, $this->engine->totalCost(-50, -10));
    }

    /* ------------------------------- analyze ------------------------------- */

    public function test_analyze_reports_viable_true_when_price_above_floor(): void
    {
        $result = $this->engine->analyze(price: 200, cmv: 100, charges: 20, targetMarginPct: 10, minMarginPct: 5);

        $this->assertTrue($result['viable']);
        $this->assertIsFloat($result['break_even']);
        $this->assertIsFloat($result['floor']);
    }

    public function test_analyze_reports_viable_false_when_price_below_floor(): void
    {
        $result = $this->engine->analyze(price: 50, cmv: 100, charges: 20, minMarginPct: 10);

        $this->assertFalse($result['viable']);
    }

    /* --------------------------- roundToCharm --------------------------- */

    public function test_round_to_charm_rounds_down_to_ninety_cents(): void
    {
        $this->assertSame(153.90, $this->engine->roundToCharm(154.23));
        $this->assertSame(99.90, $this->engine->roundToCharm(100.00));
    }

    public function test_round_to_charm_never_exceeds_input(): void
    {
        $rounded = $this->engine->roundToCharm(50.10);
        $this->assertLessThanOrEqual(50.10, $rounded);
    }

    /* --------------------------- markup legado --------------------------- */

    public function test_target_price_from_markup_matches_legacy_formula(): void
    {
        // Validado contra a planilha Cálculo Promo: PV = equilíbrio * (1+markup%)
        $cost = 100.0;
        $charges = 20.0;
        $markup = 23.433;

        $expected = round($this->engine->breakEven($cost, $charges) * (1 + $markup / 100), 2);
        $this->assertSame($expected, $this->engine->targetPriceFromMarkup($cost, $charges, $markup));
    }

    public function test_markup_to_margin_pct_conversion(): void
    {
        // markup 100% sobre o custo equivale a margem líquida de 50% sobre o preço
        $this->assertSame(50.0, $this->engine->markupToMarginPct(100));
    }

    /* ---------------------------------------------------------------------
     * tieredBreakEven — regressão contra a fórmula duplicada que existia em
     * ManagementDecisionService e PromoCalculatorService (valores conferidos
     * manualmente antes da unificação, para nunca mudar o resultado da
     * planilha por acidente).
     * --------------------------------------------------------------------- */

    public function test_tiered_break_even_mode_none_equals_plain_break_even(): void
    {
        $this->assertSame(
            $this->engine->breakEven(100, 30),
            $this->engine->tieredBreakEven(100, 30, 'none')
        );
    }

    public function test_tiered_break_even_ml_half_fee_special_case_below_12_50(): void
    {
        // custo baixo o suficiente para o "base" estimado ficar < 12,50 =>
        // regra especial: base + base/2 (equivalente a base * 1.5).
        $result = $this->engine->tieredBreakEven(5, 10, 'ml');
        $base = 5 / (1 - 0.10); // 5.555...
        $this->assertLessThan(12.5, $base);
        $this->assertEqualsWithDelta(round($base * 1.5, 2), $result, 0.01);
    }

    public function test_tiered_break_even_ml_applies_fixed_fee_tier(): void
    {
        // custo=20, encargos=10% => base = 22.22 (entre 12.5 e 29) => fee=6.25
        $result = $this->engine->tieredBreakEven(20, 10, 'ml');
        $expected = round((20 + 6.25) / 0.90, 2);
        $this->assertSame($expected, $result);
    }

    public function test_tiered_break_even_ml_zero_fee_top_tier(): void
    {
        // base bem alto (>79) cai na última faixa, fee=0 => igual ao "none".
        $result = $this->engine->tieredBreakEven(500, 10, 'ml');
        $this->assertSame($this->engine->breakEven(500, 10), $result);
    }

    public function test_tiered_break_even_shopee_applies_correct_tier(): void
    {
        // custo=60, encargos=20% => base=75 (<=79.99) => fee=4
        $result = $this->engine->tieredBreakEven(60, 20, 'shopee');
        $expected = round((60 + 4) / 0.80, 2);
        $this->assertSame($expected, $result);
    }

    public function test_tiered_break_even_shopee_second_tier_uses_16_not_4(): void
    {
        // Documenta a correção do bug do protótipo original (CLAUDE.md):
        // a faixa 80,00–99,99 usa R$16, NÃO R$4.
        // custo=85, encargos=20% => base=106.25 (>99.99, cai na faixa 199.99=>20)
        // então força um custo que caia exatamente na faixa 80-99.99:
        // custo=70, encargos=20% => base=87.5 (entre 79.99 e 99.99) => fee=16
        $result = $this->engine->tieredBreakEven(70, 20, 'shopee');
        $expected = round((70 + 16) / 0.80, 2);
        $this->assertSame($expected, $result);
        $this->assertNotSame(round((70 + 4) / 0.80, 2), $result);
    }

    public function test_tiered_break_even_returns_null_when_charges_impossible(): void
    {
        $this->assertNull($this->engine->tieredBreakEven(100, 100, 'ml'));
        $this->assertNull($this->engine->tieredBreakEven(100, 105, 'shopee'));
    }
}
