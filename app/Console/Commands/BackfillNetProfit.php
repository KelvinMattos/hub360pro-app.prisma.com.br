<?php

namespace App\Console\Commands;

use App\Services\Financial\NetProfitCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula orders.net_profit para o histórico (a coluna era nula em todos
 * os pedidos até esta correção). Idempotente — pode ser rodado quantas vezes
 * for preciso; sempre sobrescreve com o valor recalculado.
 *
 *   php artisan orders:backfill-net-profit
 *   php artisan orders:backfill-net-profit --company=1
 *   php artisan orders:backfill-net-profit --dry-run
 */
class BackfillNetProfit extends Command
{
    protected $signature = 'orders:backfill-net-profit
        {--company= : Restringe a uma empresa}
        {--chunk=1000 : Tamanho do lote}
        {--dry-run : Só conta quantos pedidos seriam afetados, não grava}';

    protected $description = 'Recalcula orders.net_profit para pedidos existentes (total_amount - comissão - imposto - frete - taxa fixa - CMV)';

    public function handle(NetProfitCalculator $calculator): int
    {
        if (!$calculator->schemaReady()) {
            $this->error('Schema incompleto: orders.net_profit ou uma das colunas de custo não existe. Rode as migrations antes.');
            return self::FAILURE;
        }

        $query = DB::table('orders')
            ->select('id', 'total_amount', 'cost_fee_commission', 'cost_fee_taxes', 'cost_fee_shipping', 'cost_fee_fixed', 'cost_products');

        if ($company = $this->option('company')) {
            $query->where('company_id', (int) $company);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('Nenhum pedido encontrado.');
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("Dry-run: {$total} pedidos seriam recalculados. Nada foi gravado.");
            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $updated = 0;
        $query->orderBy('id')->chunk($chunk, function ($rows) use ($calculator, &$updated, $bar) {
            $updated += $calculator->backfillChunk($rows);
            $bar->advance($rows->count());
        });

        $bar->finish();
        $this->newLine();
        $this->info("Concluído: {$updated} pedidos com net_profit recalculado.");

        return self::SUCCESS;
    }
}
