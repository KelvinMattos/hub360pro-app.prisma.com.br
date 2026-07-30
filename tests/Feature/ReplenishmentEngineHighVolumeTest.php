<?php

namespace Tests\Feature;

use App\Services\Inventory\ReplenishmentEngine;
use App\Services\SkuStrategyClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bug real reportado em produção: com o catálogo inteiro (~78 mil SKUs),
 * `ReplenishmentEngine::salesByProduct()` fazia
 * `whereIn('order_items.product_id', $productIds)` com um id por produto —
 * SQLSTATE[HY000]: 1390 "General error: Prepared statement contains too many
 * placeholders" (limite do MySQL é 65535). `SkuStrategyClassifier` tinha o
 * mesmo padrão (mesmo risco, roda diariamente via `sku:classify-strategy`).
 *
 * A correção troca o whereIn(ids) por um join com `products` filtrado por
 * company_id — a query nunca mais recebe uma lista de ids como parâmetro,
 * então o volume de SKUs deixa de importar para o número de placeholders.
 * Este teste usa 70.000 produtos (acima do limite de 65535) para provar que
 * o cenário exato do incidente não quebra mais.
 */
class ReplenishmentEngineHighVolumeTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT_COUNT = 70000;

    public function test_compute_company_does_not_exceed_mysql_placeholder_limit(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Grande', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $productIds = [];
        $chunk = [];
        for ($i = 0; $i < self::PRODUCT_COUNT; $i++) {
            $chunk[] = [
                'company_id' => $companyId, 'sku' => "SKU-$i", 'title' => "Produto $i",
                'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($chunk) >= 2000) {
                DB::table('products')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            DB::table('products')->insert($chunk);
        }

        // Um único pedido cobrindo vendas de 5 mil produtos — suficiente para
        // exercitar a agregação real sem pagar o custo de 70 mil inserts de pedido.
        $orderId = DB::table('orders')->insertGetId([
            'company_id' => $companyId, 'status' => 'approved', 'total_amount' => 100,
            'date_created' => $now->copy()->subDays(2), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $sellingProductIds = DB::table('products')->where('company_id', $companyId)->limit(5000)->pluck('id');
        $itemsChunk = [];
        foreach ($sellingProductIds as $pid) {
            $itemsChunk[] = [
                'order_id' => $orderId, 'product_id' => $pid, 'sku' => 'x',
                'quantity' => 2, 'unit_price' => 100, 'unit_cost' => 50,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($itemsChunk) >= 2000) {
                DB::table('order_items')->insert($itemsChunk);
                $itemsChunk = [];
            }
        }
        if ($itemsChunk) {
            DB::table('order_items')->insert($itemsChunk);
        }

        $engine = app(ReplenishmentEngine::class);

        $start = microtime(true);
        $count = $engine->computeCompany($companyId);
        $elapsed = microtime(true) - $start;

        fwrite(STDERR, sprintf(
            "\n[perf] ReplenishmentEngine::computeCompany() com %d produtos (%d com venda): %.2fs\n",
            self::PRODUCT_COUNT, $sellingProductIds->count(), $elapsed
        ));

        $this->assertSame(self::PRODUCT_COUNT, $count);
        $this->assertSame(self::PRODUCT_COUNT, DB::table('replenishment_plan')->where('company_id', $companyId)->count());

        // Uma amostra vendida precisa ter velocidade real computada (não zerada).
        $sample = DB::table('replenishment_plan')
            ->where('company_id', $companyId)
            ->where('product_id', $sellingProductIds->first())
            ->first();
        $this->assertGreaterThan(0, (float) $sample->velocity_weighted);
    }

    /** Mesmo padrão de bug, mesma correção — SkuStrategyClassifier roda diariamente sobre o catálogo inteiro. */
    public function test_sku_strategy_classifier_does_not_exceed_mysql_placeholder_limit(): void
    {
        $companyId = DB::table('companies')->insertGetId([
            'name' => 'Empresa Grande 2', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $now = now();
        $chunk = [];
        for ($i = 0; $i < self::PRODUCT_COUNT; $i++) {
            $chunk[] = [
                'company_id' => $companyId, 'sku' => "SKU2-$i", 'title' => "Produto $i",
                'sale_price' => 100, 'cost_price' => 50, 'stock_quantity' => 10,
                'created_at' => $now, 'updated_at' => $now,
            ];
            if (count($chunk) >= 2000) {
                DB::table('products')->insert($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            DB::table('products')->insert($chunk);
        }

        $count = app(SkuStrategyClassifier::class)->classifyCompany($companyId);

        $this->assertSame(self::PRODUCT_COUNT, $count);
    }
}
