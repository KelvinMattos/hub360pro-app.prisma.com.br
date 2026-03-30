<?php

namespace App\Filament\Resources;

use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationLabel = 'Vendas/Pedidos';
    protected static ?string $modelLabel = 'Pedido';
    protected static ?string $pluralModelLabel = 'Pedidos';
    protected static ?string $navigationGroup = 'Vendas e Relacionamento';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Identificação do Pedido')
                    ->schema([
                        Forms\Components\TextInput::make('external_id')
                            ->label('ID Externo')
                            ->required()
                            ->disabled()
                            ->dehydrated(false),
                        Forms\Components\Select::make('integration_id')
                            ->label('Canal')
                            ->relationship('integration', 'channel')
                            ->required()
                            ->disabled(),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'paid' => 'Pago',
                                'shipped' => 'Enviado',
                                'delivered' => 'Entregue',
                                'cancelled' => 'Cancelado',
                            ])
                            ->required(),
                    ])->columns(['md' => 3]),

                Forms\Components\Section::make('Financeiro')
                    ->schema([
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Valor Total')
                            ->numeric()
                            ->prefix('R$')
                            ->disabled(),
                        Forms\Components\TextInput::make('net_amount')
                            ->label('Líquido')
                            ->numeric()
                            ->prefix('R$')
                            ->disabled(),
                    ])->columns(['md' => 2]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('date_created')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('integration.channel')
                    ->label('Canal')
                    ->badge()
                    ->color(function (string $state): string {
                        return match($state) {
                            'mercadolibre' => 'warning',
                            'shopee' => 'danger',
                            default => 'gray'
                        };
                    }),
                Tables\Columns\TextColumn::make('external_id')
                    ->label('ID Pedido')
                    ->searchable()
                    ->fontFamily('mono')
                    ->copyable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(function (string $state): string {
                        return match($state) {
                            'paid' => 'success',
                            'shipped' => 'info',
                            'delivered' => 'success',
                            'cancelled' => 'danger',
                            default => 'gray'
                        };
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('integration_id')
                    ->label('Canal')
                    ->relationship('integration', 'channel'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}