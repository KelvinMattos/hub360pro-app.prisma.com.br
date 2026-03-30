<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupplierResource\Pages;
use App\Models\Supplier;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SupplierResource extends Resource
{
    protected static ?string $model = Supplier::class;
    protected static ?string $navigationIcon = 'heroicon-o-truck';
    protected static ?string $navigationLabel = 'Fornecedores';
    protected static ?string $modelLabel = 'Fornecedor';
    protected static ?string $pluralModelLabel = 'Fornecedores';
    protected static ?string $navigationGroup = 'Suprimentos';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('Dados do Fornecedor')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Nome/Razão Social')
                            ->required()
                            ->maxLength(191),
                        Forms\Components\TextInput::make('email')
                            ->label('E-mail')
                            ->email()
                            ->maxLength(191),
                        Forms\Components\TextInput::make('phone')
                            ->label('Telefone')
                            ->tel(),
                    ])->columns(['md' => 2]),
                
                Forms\Components\Section::make('Documentação e Endereço')
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
                    ])->columns(['md' => 2]),
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
                Tables\Columns\TextColumn::make('doc_number')
                    ->label('Documento')
                    ->searchable(),
                Tables\Columns\TextColumn::make('city')
                    ->label('Cidade/UF')
                    ->getStateUsing(fn($record): string => (string)($record->city . '/' . $record->state)),
                Tables\Columns\TextColumn::make('email')
                    ->label('E-mail')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('phone')
                    ->label('Telefone'),
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
            'index' => Pages\ListSuppliers::route('/'),
            'create' => Pages\CreateSupplier::route('/create'),
            'edit' => Pages\EditSupplier::route('/{record}/edit'),
        ];
    }
}