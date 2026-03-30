<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PurchaseOrderResource\Pages;
use App\Models\PurchaseOrder;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class PurchaseOrderResource extends Resource
{
    protected static ?string $model = PurchaseOrder::class;
    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';
    protected static ?string $navigationLabel = 'Pedidos de Compra';
    protected static ?string $modelLabel = 'Pedido de Compra';
    protected static ?string $pluralModelLabel = 'Pedidos de Compra';
    protected static ?string $navigationGroup = 'Suprimentos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Informações Gerais')
                    ->schema([
                        Forms\Components\TextInput::make('order_number')
                            ->label('Número do Pedido')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Forms\Components\Select::make('supplier_id')
                            ->label('Fornecedor')
                            ->relationship('supplier', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pendente',
                                'ordered' => 'Enviado',
                                'received' => 'Recebido Total',
                                'partial' => 'Recebido Parcial',
                                'cancelled' => 'Cancelado',
                            ])
                            ->required()
                            ->default('pending'),
                    ])->columns(['md' => 3]),

                Forms\Components\Section::make('Valores e Datas')
                    ->schema([
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Valor Total')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0),
                        Forms\Components\DatePicker::make('ordered_at')
                            ->label('Data do Pedido')
                            ->default(now()),
                        Forms\Components\DatePicker::make('expected_at')
                            ->label('Previsão de Entrega'),
                    ])->columns(['md' => 3]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')
                    ->label('Pedido')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono'),
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Fornecedor')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'gray',
                        'ordered' => 'warning',
                        'received' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state): string => match((string)$state) {
                        'pending' => 'Pendente',
                        'ordered' => 'Enviado',
                        'received' => 'Recebido',
                        'partial' => 'Parcial',
                        'cancelled' => 'Cancelado',
                        default => (string)$state
                    }),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('ordered_at')
                    ->label('Data')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pendente',
                        'ordered' => 'Enviado',
                        'received' => 'Recebido',
                        'cancelled' => 'Cancelado',
                    ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPurchaseOrders::route('/'),
            'create' => Pages\CreatePurchaseOrder::route('/create'),
            'edit' => Pages\EditPurchaseOrder::route('/{record}/edit'),
        ];
    }
}