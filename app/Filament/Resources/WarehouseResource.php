<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WarehouseResource\Pages;
use App\Models\Warehouse;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class WarehouseResource extends Resource
{
    protected static ?string $model = Warehouse::class;
    protected static ?string $navigationIcon = 'heroicon-o-home-modern';
    protected static ?string $navigationLabel = 'Depósitos';
    protected static ?string $modelLabel = 'Depósito';
    protected static ?string $pluralModelLabel = 'Depósitos';
    protected static ?string $navigationGroup = 'Estoque';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\Section::make('Informações do Depósito')
            ->schema([
                Forms\Components\TextInput::make('name')
                ->label('Nome do Depósito')
                ->placeholder('Ex: Almoxarifado Central, Loja 01')
                ->required(),
                Forms\Components\Toggle::make('is_default')
                ->label('Depósito Padrão')
                ->default(false),
            ])->columns(['md' => 2]),

            Forms\Components\Section::make('Localização')
            ->schema([
                Forms\Components\TextInput::make('address')
                ->label('Endereço Completo')
                ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')
            ->label('Depósito')
            ->searchable()
            ->sortable(),
            Tables\Columns\IconColumn::make('is_default')
            ->label('Padrão')
            ->boolean(),
            Tables\Columns\TextColumn::make('address')
            ->label('Endereço')
            ->limit(50),
        ])
            ->filters([])
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
            'index' => Pages\ListWarehouses::route('/'),
            'create' => Pages\CreateWarehouse::route('/create'),
            'edit' => Pages\EditWarehouse::route('/{record}/edit'),
        ];
    }
}