<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TransactionResource\Pages;
use App\Models\Transaction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TransactionResource extends Resource
{
    protected static ?string $model = Transaction::class;
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationLabel = 'Transações';
    protected static ?string $modelLabel = 'Transação';
    protected static ?string $pluralModelLabel = 'Transações';
    protected static ?string $navigationGroup = 'Financeiro';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Detalhes da Transação')
                    ->schema([
                        Forms\Components\Select::make('bank_account_id')
                            ->label('Conta Bancária')
                            ->relationship('bankAccount', 'name')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\Select::make('type')
                            ->label('Tipo')
                            ->options([
                                'revenue' => 'Receita',
                                'expense' => 'Despesa',
                            ])
                            ->required(),
                        Forms\Components\TextInput::make('amount')
                            ->label('Valor')
                            ->numeric()
                            ->prefix('R$')
                            ->required(),
                    ])->columns(['md' => 3]),

                Forms\Components\Section::make('Classificação e Status')
                    ->schema([
                        Forms\Components\TextInput::make('description')
                            ->label('Descrição/Motivo')
                            ->required()
                            ->maxLength(191),
                        Forms\Components\DatePicker::make('due_date')
                            ->label('Vencimento')
                            ->required()
                            ->default(now()),
                        Forms\Components\DatePicker::make('payment_date')
                            ->label('Data de Pagamento'),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'pending' => 'Pendente',
                                'paid' => 'Pago',
                                'cancelled' => 'Cancelado',
                            ])
                            ->required()
                            ->default('pending'),
                    ])->columns(['md' => 2]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('due_date')
                    ->label('Vencimento')
                    ->date('d/m/Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('description')
                    ->label('Descrição')
                    ->searchable()
                    ->limit(30),
                Tables\Columns\TextColumn::make('bankAccount.name')
                    ->label('Conta')
                    ->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'income' => 'success',
                        'expense' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state): string => (string)($state === 'revenue' ? 'Receita' : 'Despesa')),
                Tables\Columns\TextColumn::make('amount')
                    ->label('Valor')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'confirmed' => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn($state): string => match($state) {
                        'pending' => 'Pendente',
                        'paid' => 'Pago',
                        'cancelled' => 'Cancelado',
                        default => (string)$state
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type')
                    ->options(['revenue' => 'Receitas', 'expense' => 'Despesas']),
                Tables\Filters\SelectFilter::make('status')
                    ->options(['pending' => 'Pendente', 'paid' => 'Pago', 'cancelled' => 'Cancelado']),
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
            'index' => Pages\ListTransactions::route('/'),
            'create' => Pages\CreateTransaction::route('/create'),
            'edit' => Pages\EditTransaction::route('/{record}/edit'),
        ];
    }
}