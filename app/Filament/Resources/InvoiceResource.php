<?php

namespace App\Filament\Resources;

use App\Filament\Resources\InvoiceResource\Pages;
use App\Models\Invoice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-check';
    protected static ?string $navigationLabel = 'Faturamento (NF-e)';
    protected static ?string $modelLabel = 'Nota Fiscal';
    protected static ?string $pluralModelLabel = 'Notas Fiscais';
    protected static ?string $navigationGroup = 'Vendas e Relacionamento';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados da Nota')
                    ->schema([
                        Forms\Components\Select::make('order_id')
                            ->relationship('order', 'external_id')
                            ->label('Pedido Relacionado')
                            ->required()
                            ->searchable()
                            ->preload(),
                        Forms\Components\TextInput::make('invoice_number')
                            ->label('Número da Nota')
                            ->required(),
                        Forms\Components\TextInput::make('series')
                            ->label('Série')
                            ->default('1'),
                        Forms\Components\TextInput::make('access_key')
                            ->label('Chave de Acesso')
                            ->columnSpanFull(),
                    ])->columns(['md' => 3]),

                Forms\Components\Section::make('Valores e Datas')
                    ->schema([
                        Forms\Components\TextInput::make('total_amount')
                            ->label('Valor Total')
                            ->numeric()
                            ->prefix('R$')
                            ->required(),
                        Forms\Components\TextInput::make('tax_amount')
                            ->label('Impostos')
                            ->numeric()
                            ->prefix('R$')
                            ->default(0),
                        Forms\Components\DateTimePicker::make('issued_at')
                            ->label('Data de Emissão')
                            ->default(now()),
                        Forms\Components\Select::make('status')
                            ->label('Status')
                            ->options([
                                'draft' => 'Rascunho',
                                'pending' => 'Pendente',
                                'authorized' => 'Autorizada',
                                'cancelled' => 'Cancelada',
                                'error' => 'Erro',
                            ])
                            ->required()
                            ->default('draft'),
                    ])->columns(['md' => 2]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('invoice_number')
                    ->label('Número/Série')
                    ->formatStateUsing(fn($record) => "{$record->invoice_number}-{$record->series}")
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('order.external_id')
                    ->label('Pedido')
                    ->searchable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->label('Total')
                    ->money('BRL')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(function (string $state): string {
                        return match($state) {
                            'authorized' => 'success',
                            'pending' => 'warning',
                            'error', 'cancelled' => 'danger',
                            default => 'gray'
                        };
                    })
                    ->formatStateUsing(fn(string $state): string => match($state) {
                        'draft' => 'Rascunho',
                        'pending' => 'Pendente',
                        'authorized' => 'Autorizada',
                        'cancelled' => 'Cancelada',
                        'error' => 'Erro',
                        default => $state
                    }),
                Tables\Columns\TextColumn::make('issued_at')
                    ->label('Emissão')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'authorized' => 'Autorizadas',
                        'pending' => 'Pendentes',
                        'cancelled' => 'Canceladas',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInvoices::route('/'),
            'create' => Pages\CreateInvoice::route('/create'),
            'edit' => Pages\EditInvoice::route('/{record}/edit'),
        ];
    }
}