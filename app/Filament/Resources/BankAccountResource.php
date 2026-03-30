<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankAccountResource\Pages;
use App\Models\BankAccount;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class BankAccountResource extends Resource
{
    protected static ?string $model = BankAccount::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-library';
    protected static ?string $navigationLabel = 'Contas Bancárias';
    protected static ?string $modelLabel = 'Conta Bancária';
    protected static ?string $pluralModelLabel = 'Contas Bancárias';
    protected static ?string $navigationGroup = 'Financeiro';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados da Conta')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome Amigável')
                            ->placeholder('Ex: Conta Principal, Caixa Interno')
                            ->required(),
                        Forms\Components\Select::make('type')
                            ->label('Tipo de Conta')
                            ->options([
                                'checking' => 'Conta Corrente',
                                'savings' => 'Poupança',
                                'cash' => 'Caixa / Dinheiro',
                            ])
                            ->required()
                            ->default('checking'),
                    ])->columns(['md' => 2]),

                Forms\Components\Section::make('Informações Bancárias')
                    ->schema([
                        Forms\Components\TextInput::make('bank_name')
                            ->label('Banco'),
                        Forms\Components\TextInput::make('account_number')
                            ->label('Número da Conta'),
                        Forms\Components\TextInput::make('balance')
                            ->label('Saldo Atual')
                            ->numeric()
                            ->prefix('R$')
                            ->required()
                            ->default(0),
                    ])->columns(['md' => 3]),
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
                Tables\Columns\TextColumn::make('bank_name')
                    ->label('Banco'),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn($state): string => match((string)$state) {
                        'checking' => 'Corrente',
                        'savings' => 'Poupança',
                        'cash' => 'Caixa',
                        default => (string)$state
                    }),
                Tables\Columns\TextColumn::make('balance')
                    ->label('Saldo')
                    ->money('BRL')
                    ->sortable()
                    ->color(fn ($state): string => (float)$state < 0 ? 'danger' : 'success'),
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
            'index' => Pages\ListBankAccounts::route('/'),
            'create' => Pages\CreateBankAccount::route('/create'),
            'edit' => Pages\EditBankAccount::route('/{record}/edit'),
        ];
    }
}