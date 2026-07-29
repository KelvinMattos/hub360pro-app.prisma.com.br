<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Services\Financial\NetProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NetProfitCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private NetProfitCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new NetProfitCalculator();
    }

    public function test_calculate_from_values_subtracts_all_five_components(): void
    {
        // 1000 - (140 comissão + 60 imposto + 25 frete + 6 taxa fixa + 400 CMV) = 369
        $net = $this->calculator->calculateFromValues(1000, 140, 60, 25, 6, 400);

        $this->assertSame(369.0, $net);
    }

    public function test_calculate_from_values_can_be_negative_prejuizo(): void
    {
        // Custos maiores que o total_amount => prejuízo, resultado negativo.
        $net = $this->calculator->calculateFromValues(100, 40, 30, 25, 6, 50);

        $this->assertSame(-51.0, $net);
    }

    public function test_calculate_from_values_with_all_zero_costs(): void
    {
        $net = $this->calculator->calculateFromValues(250, 0, 0, 0, 0, 0);

        $this->assertSame(250.0, $net);
    }

    public function test_calculate_rounds_to_two_decimals(): void
    {
        $net = $this->calculator->calculateFromValues(100.333, 1.111, 1.111, 1.111, 1.111, 1.111);

        $this->assertSame(94.78, $net);
    }

    public function test_calculate_reads_from_order_model(): void
    {
        $orderId = $this->insertRawOrder([
            'total_amount' => 500,
            'cost_fee_commission' => 70,
            'cost_fee_taxes' => 30,
            'cost_fee_shipping' => 15,
            'cost_fee_fixed' => 5,
            'cost_products' => 200,
        ]);

        $order = Order::find($orderId);

        $this->assertSame(180.0, $this->calculator->calculate($order));
    }

    public function test_calculate_treats_null_attributes_as_zero(): void
    {
        // As colunas são NOT NULL DEFAULT 0 no schema real, mas o serviço
        // precisa ser defensivo mesmo assim (ex.: model parcialmente
        // hidratado, select() sem essas colunas). Testa direto na instância,
        // sem persistir — inserir NULL violaria a constraint do banco.
        $order = new Order();
        $order->total_amount = 300;

        $this->assertSame(300.0, $this->calculator->calculate($order));
    }

    public function test_recalculate_and_save_persists_net_profit_column(): void
    {
        $orderId = $this->insertRawOrder([
            'total_amount' => 400,
            'cost_fee_commission' => 40,
            'cost_fee_taxes' => 20,
            'cost_fee_shipping' => 10,
            'cost_fee_fixed' => 5,
            'cost_products' => 150,
        ]);

        $order = Order::find($orderId);
        $result = $this->calculator->recalculateAndSave($order);

        $this->assertSame(175.0, $result);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'net_profit' => 175.0]);
    }

    public function test_schema_ready_is_true_after_migration(): void
    {
        $this->assertTrue($this->calculator->schemaReady());
    }

    /** Insere direto via query builder — evita o $fillable do model Order, que hoje referencia colunas inexistentes fora do escopo desta correção. */
    private function insertRawOrder(array $overrides = []): int
    {
        return \Illuminate\Support\Facades\DB::table('orders')->insertGetId(array_merge([
            'company_id' => null,
            'status' => 'paid',
            'total_amount' => 0,
            'cost_fee_commission' => 0,
            'cost_fee_taxes' => 0,
            'cost_fee_shipping' => 0,
            'cost_fee_fixed' => 0,
            'cost_products' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
