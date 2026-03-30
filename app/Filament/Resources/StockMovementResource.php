<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StockMovementResource\Pages;
use App\Models\StockMovement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class StockMovementResource extends Resource
{
    protected static ?string $model = StockMovement::class;
    protected static ?string $navigationIcon = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationLabel = 'Movimentações de Estoque';
    protected static ?string $modelLabel = 'Movimentação';
    protected static ?string $pluralModelLabel = 'Movimentações';
    protected static ?string $navigationGroup = 'Estoque e Logística';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Registro de Movimentação')
                    ->schema([
                        Forms\Components\Select::make('product_id')
                            ->label('Produto')
                            ->relationship('product', 'title')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('warehouse_id')
                            ->label('Depósito')
                            ->relationship('warehouse', 'name')
                            ->required(),
                        Forms\Components\TextInput::make('quantity')
                            ->label('Quantidade')
                            ->numeric()
                            ->required(),
                        Forms\Components\Select::make('type')
                            ->label('Tipo')
                            ->options([
                                'in' => 'Entrada',
                                'out' => 'Saída',
                                'adjustment' => 'Ajuste',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('reason')
                            ->label('Motivo')
                            ->maxLength(191),
                    ])->columns(['md' => 2]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Data')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\TextColumn::make('product.title')
                    ->label('Produto')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('warehouse.name')
                    ->label('Depósito')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'in' => 'success',
                        'out' => 'danger',
                        'adjustment' => 'warning',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state): string => match((string)$state) {
                        'in' => 'Entrada',
                        'out' => 'Saída',
                        'adjustment' => 'Ajuste',
                        default => (string)$state
                    }),
                Tables\Columns\TextColumn::make('quantity')
                    ->label('Qtd')
                    ->sortable(),
                Tables\Columns\TextColumn::make('reason')
                    ->label('Motivo')
                    ->limit(20)
                    ->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(['in' => 'Entrada', 'out' => 'Saída', 'adjustment' => 'Ajuste']),
                Tables\Filters\SelectFilter::make('warehouse_id')
                    ->label('Depósito')
                    ->relationship('warehouse', 'name'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListStockMovements::route('/'),
            'create' => Pages\CreateStockMovement::route('/create'),
        ];
    }
}