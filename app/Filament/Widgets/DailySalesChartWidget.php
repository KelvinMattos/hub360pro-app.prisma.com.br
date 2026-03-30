<?php

namespace App\Filament\Widgets;

use Filament\Widgets\ChartWidget;

class DailySalesChartWidget extends ChartWidget
{
    protected static ?string $heading = 'Vendas Diárias';
    protected static ?int $sort = 2;
    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        return [
            'datasets' => [
                [
                    'label' => 'Vendas Realizadas (R$)',
                    'data' => [12500, 15000, 11000, 18000, 21000, 19500, 24000],
                    'backgroundColor' => 'rgba(99, 102, 241, 0.2)',
                    'borderColor' => '#6366f1',
                ],
            ],
            'labels' => ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}