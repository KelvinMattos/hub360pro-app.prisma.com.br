<?php

namespace Tests\Unit;

use App\Services\ChannelConfigService;
use App\Services\DecisionCycleEngine;
use App\Services\PricingEngine;
use App\Services\RepricingEngine;
use PHPUnit\Framework\TestCase;

/**
 * diffInDiffTotal() é pura (sem I/O) — testa o cálculo de
 * diferenças-em-diferenças isoladamente, sem precisar de banco.
 */
class DecisionCycleEngineDiDTest extends TestCase
{
    private DecisionCycleEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $pricingEngine = new PricingEngine();
        $channelConfig = $this->createStub(ChannelConfigService::class);
        $repricingEngine = new RepricingEngine($pricingEngine, $channelConfig);
        $this->engine = new DecisionCycleEngine($pricingEngine, $channelConfig, $repricingEngine);
    }

    public function test_did_is_zero_when_both_groups_move_identically(): void
    {
        // Tratamento e controle sobem na mesma proporção -> nenhum efeito atribuível ao preço.
        $roi = $this->engine->diffInDiffTotal(1000, 1200, 500, 600, 30, 30);
        $this->assertSame(0.0, $roi);
    }

    public function test_did_is_positive_when_treatment_grows_more_than_control(): void
    {
        // Controle cresce 10% (500->550) -> contrafactual do tratamento seria 1000*1.10=1100 (36.67/dia).
        // Tratamento realmente foi para 1500 (50/dia) -> excedente de 13.33/dia * 30 dias = 400.
        $roi = $this->engine->diffInDiffTotal(1000, 1500, 500, 550, 30, 30);
        $this->assertEqualsWithDelta(400.0, $roi, 0.5);
    }

    public function test_did_is_negative_when_treatment_falls_relative_to_control(): void
    {
        $roi = $this->engine->diffInDiffTotal(1000, 800, 500, 550, 30, 30);
        $this->assertLessThan(0, $roi);
    }

    public function test_did_handles_different_baseline_and_after_day_counts(): void
    {
        // Baseline de 30 dias, mas o "after" só tem 10 dias corridos ainda (ciclo recém-iniciado).
        $roi = $this->engine->diffInDiffTotal(3000, 1000, 1500, 500, 30, 10);
        // tratamento: 100/dia antes, 100/dia depois (sem mudança); controle: 50/dia antes, 50/dia depois.
        $this->assertEqualsWithDelta(0.0, $roi, 0.01);
    }

    public function test_did_zero_baseline_does_not_error(): void
    {
        $roi = $this->engine->diffInDiffTotal(0, 100, 0, 50, 30, 30);
        $this->assertIsFloat($roi);
    }
}
