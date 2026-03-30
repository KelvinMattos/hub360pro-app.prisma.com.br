<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FinancialReportResource\Pages;
use App\Models\FinancialReport;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Infolists;
use Filament\Infolists\Infolist;

class FinancialReportResource extends Resource
{
    protected static ?string $model = FinancialReport::class;
    protected static ?string $navigationIcon = 'heroicon-o-presentation-chart-line';
    protected static ?string $navigationLabel = 'Relatórios Financeiros';
    protected static ?string $modelLabel = 'Relatório';
    protected static ?string $pluralModelLabel = 'Relatórios';
    protected static ?string $navigationGroup = 'Gestão Financeira';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
            Forms\Components\TextInput::make('name')
            ->label('Nome do Relatório')
            ->required(),
            Forms\Components\Select::make('type')
            ->label('Tipo')
            ->options([
                'dre' => 'DRE (Demonstração de Resultado)',
                'cash_flow' => 'Fluxo de Caixa',
            ])
            ->required()
            ->default('dre'),
            Forms\Components\DatePicker::make('start_date')
            ->label('Data Início')
            ->required(),
            Forms\Components\DatePicker::make('end_date')
            ->label('Data Fim')
            ->required(),
        ]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([
            Infolists\Components\Section::make('Resumo da DRE')
            ->schema([
                Infolists\Components\TextEntry::make('data.gross_revenue')
                ->label('Receita Bruta')
                ->money('BRL'),
                Infolists\Components\TextEntry::make('data.taxes')
                ->label('Impostos')
                ->money('BRL')
                ->color('danger'),
                Infolists\Components\TextEntry::make('data.net_revenue')
                ->label('Receita Líquida')
                ->weight('bold'),
                Infolists\Components\TextEntry::make('data.cpv')
                ->label('CPV (Custo Mercadorias)')
                ->money('BRL')
                ->color('danger'),
                Infolists\Components\TextEntry::make('data.gross_profit')
                ->label('Lucro Bruto')
                ->money('BRL')
                ->weight('bold'),
                Infolists\Components\TextEntry::make('data.operating_expenses')
                ->label('Despesas Operacionais')
                ->money('BRL')
                ->color('danger'),
                Infolists\Components\TextEntry::make('data.net_income')
                ->label('Lucro Líquido Final')
                ->money('BRL')
                ->size('lg')
                ->weight('black')
                ->color(fn(FinancialReport $record): string => ($record->data['net_income'] ?? 0) > 0 ? 'success' : 'danger'),
            ])->columns(['default' => 3]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            Tables\Columns\TextColumn::make('name')
            ->label('Título')
            ->searchable(),
            Tables\Columns\TextColumn::make('type')
            ->label('Tipo')
            ->badge()
            ->color(fn(string $state): string => $state === 'dre' ? 'primary' : 'warning'),
            Tables\Columns\TextColumn::make('start_date')
            ->label('Período')
            ->formatStateUsing(fn($record) => $record->start_date->format('d/m/Y') . ' - ' . $record->end_date->format('d/m/Y')),
            Tables\Columns\TextColumn::make('created_at')
            ->label('Gerado em')
            ->dateTime('d/m/Y H:i'),
        ])
            ->filters([])
            ->actions([
            Tables\Actions\ViewAction::make(),
            Tables\Actions\DeleteAction::make(),
        ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFinancialReports::route('/'),
            'create' => Pages\CreateFinancialReport::route('/create'),
            'view' => Pages\ViewFinancialReport::route('/{record}'),
        ];
    }
}