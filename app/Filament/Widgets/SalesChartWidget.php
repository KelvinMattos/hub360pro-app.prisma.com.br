<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use Filament\Widgets\ChartWidget as BaseChartWidget;

class SalesChartWidget extends BaseChartWidget
{
    protected static ?string $heading = 'Vendas por Dia (Últimos 30 dias)';
    protected static ?int $sort = 2;

    protected function getData(): array
    {
        // Busca os dados de vendas para os últimos 30 dias.
        // O fallback manual é utilizado porque o pacote 'flowframe/laravel-trend' não está instalado.
        $data = collect(range(29, 0))->map(function ($days) {
            $date = now()->subDays($days);
            return (object)[
                'date' => $date->format('Y-m-d'),
                'aggregate' => Order::whereDate('created_at', $date)->count(),
            ];
        });

        return [
            'datasets' => [
                [
                    'label' => 'Pedidos',
                    'data' => $data->map(fn ($value) => $value->aggregate),
                    'backgroundColor' => 'rgba(54, 162, 235, 0.2)',
                    'borderColor' => 'rgb(54, 162, 235)',
                    'fill' => 'start',
                ],
            ],
            'labels' => $data->map(fn ($value) => \Carbon\Carbon::parse($value->date)->format('d/m')),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}