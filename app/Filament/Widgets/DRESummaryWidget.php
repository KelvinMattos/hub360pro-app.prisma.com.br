<?php

namespace App\Filament\Widgets;

use App\Services\FinancialReportService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class DRESummaryWidget extends BaseWidget
{
    protected static ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $service = new FinancialReportService();
        $data = $service->generateDRE(now()->startOfMonth(), now());

        return [
            Stat::make('Receita Bruta (Mês)', 'R$ ' . number_format($data['gross_revenue'], 2, ',', '.'))
            ->description('Total de pedidos pagos')
            ->descriptionIcon('heroicon-m-arrow-trending-up')
            ->color('success'),
            Stat::make('Lucro Líquido (Mês)', 'R$ ' . number_format($data['net_income'], 2, ',', '.'))
            ->description('Margem: ' . number_format($data['margin_percent'], 1) . '%')
            ->descriptionIcon('heroicon-m-banknotes')
            ->color($data['net_income'] > 0 ? 'success' : 'danger'),
            Stat::make('Despesas (Mês)', 'R$ ' . number_format($data['operating_expenses'], 2, ',', '.'))
            ->description('Saídas operacionais')
            ->descriptionIcon('heroicon-m-arrow-trending-down')
            ->color('warning'),
        ];
    }
}