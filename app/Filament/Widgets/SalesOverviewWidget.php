<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class SalesOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        return [
            Stat::make('Vendas Hoje', 'R$ 1.250,00')
            ->description('32% de aumento')
            ->descriptionIcon('heroicon-m-arrow-trending-up')
            ->color('success')
            ->chart([7, 2, 10, 3, 15, 4, 17]),
            Stat::make('Pedidos Pendentes', '12')
            ->description('5 Mercado Livre, 7 Shopee')
            ->descriptionIcon('heroicon-m-shopping-bag')
            ->color('warning'),
            Stat::make('Erros de Integração', '0')
            ->description('Sincronizações 100% OK')
            ->descriptionIcon('heroicon-m-check-circle')
            ->color('success'),
        ];
    }
}