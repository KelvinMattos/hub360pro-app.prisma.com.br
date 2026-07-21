<?php

namespace App\Console\Commands;

use App\Services\CatalogResetService;
use Illuminate\Console\Command;

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

    public function handle(CatalogResetService $service): int
    {
        $preview = $service->preview();

        if (empty($preview)) {
            $this->warn('Nenhuma tabela alvo encontrada.');
            return self::SUCCESS;
        }

        $this->line('Tabelas que serão ESVAZIADAS (com contagem atual):');
        $this->table(['Tabela', 'Registros'], array_map(
            fn ($r) => [$r['table'], number_format($r['count'], 0, ',', '.')],
            $preview
        ));
        $this->line('Preservadas: users, companies, channel_settings, pricing_settings, integrations e demais configurações.');

        if (!$this->option('force') && !$this->confirm('Confirma apagar TODOS esses registros? Esta ação é irreversível.')) {
            $this->info('Cancelado. Nada foi apagado.');
            return self::SUCCESS;
        }

        $result = $service->reset('cli');
        $this->info("Catálogo e vendas limpos ({$result['total']} registros removidos). Pode recomeçar as importações.");
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
