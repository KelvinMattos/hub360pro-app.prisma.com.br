<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CustomerResource\Pages;
use App\Models\Customer;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class CustomerResource extends Resource
{
    protected static ?string $model = Customer::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Clientes';

    protected static ?string $modelLabel = 'Cliente';

    protected static ?string $pluralModelLabel = 'Clientes';

    protected static ?string $navigationGroup = 'Vendas';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\Section::make('Identificação')
            ->schema([
                Forms\Components\TextInput::make('name')
                ->label('Nome Completo')
                ->required(),
                Forms\Components\TextInput::make('email')
                ->email()
                ->label('E-mail'),
                Forms\Components\TextInput::make('phone')
                ->label('Telefone/WhatsApp'),
                Forms\Components\Select::make('origin_channel')
                ->label('Origem')
                ->options([
                    'mercadolibre' => 'Mercado Livre',
                    'shopee' => 'Shopee',
                    'bling' => 'Bling',
                    'site' => 'Site Próprio',
                ]),
            ])->columns(['default' => 2]),

            Forms\Components\Section::make('Documentação e Localização')
            ->schema([
                Forms\Components\Select::make('doc_type')
                ->label('Tipo Doc')
                ->options(['CPF' => 'CPF', 'CNPJ' => 'CNPJ']),
                Forms\Components\TextInput::make('doc_number')
                ->label('Número Documento'),
                Forms\Components\TextInput::make('city')
                ->label('Cidade'),
                Forms\Components\TextInput::make('state')
                ->label('UF')
                ->maxLength(2),
            ])->columns(['default' => 2]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')
            ->label('Nome')
            ->searchable()
            ->sortable(),
            Tables\Columns\TextColumn::make('email')
            ->label('E-mail')
            ->searchable(),
            Tables\Columns\TextColumn::make('origin_channel')
            ->label('Canal')
            ->badge()
            ->color(fn(?string $state): string => match ($state) {
            'mercadolibre' => 'warning',
            'shopee' => 'danger',
            default => 'gray',
        }),
            Tables\Columns\TextColumn::make('city')
            ->label('Cidade/UF')
            ->getStateUsing(fn($record): string => "{$record->city}/{$record->state}"),
            Tables\Columns\TextColumn::make('total_spent')
            ->label('Total Gasto')
            ->money('BRL')
            ->sortable(),
        ])
            ->filters([
            Tables\Filters\SelectFilter::make('origin_channel')
            ->label('Filtrar por Canal')
            ->options([
                'mercadolibre' => 'Mercado Livre',
                'shopee' => 'Shopee',
            ]),
        ])
            ->actions([
            Tables\Actions\EditAction::make(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCustomers::route('/'),
            'create' => Pages\CreateCustomer::route('/create'),
            'edit' => Pages\EditCustomer::route('/{record}/edit'),
        ];
    }
}