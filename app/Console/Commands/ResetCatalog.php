<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limpa o catálogo e as vendas para recomeçar as importações do zero.
 * Remove produtos, dados de produto e pedidos. NÃO toca em usuários, empresas
 * nem nas configurações (channel_settings / pricing_settings).
 *
 * Uso:
 *   php artisan catalog:reset            (pede confirmação)
 *   php artisan catalog:reset --force    (sem confirmação)
 */
class ResetCatalog extends Command
{
    protected $signature = 'catalog:reset {--force : Executa sem pedir confirmação}';

    protected $description = 'Remove todos os produtos, vendas e dados de produto (mantém usuários, empresas e configurações).';

    /** Tabelas a limpar, se existirem. */
    private array $tables = [
        // Vendas
        'order_items', 'order_notifications', 'orders',
        // Produto e derivados
        'product_channel_settings', 'product_medias', 'product_kits',
        'products_meli', 'master_products', 'products',
        // Estoque e listings
        'stock_movements', 'stock_history', 'listing_histories',
    ];

    public function handle(): int
    {
        $existing = array_values(array_filter($this->tables, fn ($t) => Schema::hasTable($t)));

        if (empty($existing)) {
            $this->warn('Nenhuma tabela alvo encontrada.');
            return self::SUCCESS;
        }

        $this->line('Tabelas que serão ESVAZIADAS (com contagem atual):');
        $rows = [];
        foreach ($existing as $t) {
            $rows[] = [$t, number_format(DB::table($t)->count(), 0, ',', '.')];
        }
        $this->table(['Tabela', 'Registros'], $rows);
        $this->line('Preservadas: users, companies, channel_settings, pricing_settings, integrations e demais configurações.');

        if (!$this->option('force') && !$this->confirm('Confirma apagar TODOS esses registros? Esta ação é irreversível.')) {
            $this->info('Cancelado. Nada foi apagado.');
            return self::SUCCESS;
        }

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($existing as $t) {
                DB::table($t)->truncate();
                $this->line("  ✔ {$t} limpo");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->info('Catálogo e vendas limpos. Pode recomeçar as importações.');
        $this->newLine();
        $this->line('Ordem sugerida de importação:');
        $this->line('  1) Preços de Venda (Consulta Dinâmica)  — MARQUE "criar produtos inexistentes"');
        $this->line('  2) Produtos & Datas                     — MARQUE "criar produtos inexistentes" (traz launched_at)');
        $this->line('  3) Estoque                              — atualiza quantidades');
        $this->line('  4) Custos                               — refina o custo (opcional)');
        $this->line('  5) Produtos com Desconto                — promoção (opcional)');
        $this->line('  6) Vendas                               — pedidos');

        return self::SUCCESS;
    }
}
