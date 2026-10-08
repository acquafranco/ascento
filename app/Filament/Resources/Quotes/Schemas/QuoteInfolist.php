<?php

namespace App\Filament\Resources\Quotes\Schemas;

use App\Models\Quote;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Infolists\Components\TextEntry;

class QuoteInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([

                Section::make('📋 Información del presupuesto')
                    ->description('Detalle general del presupuesto.')
                    ->icon('heroicon-o-document-text')
                    ->schema([

                        TextEntry::make('title')
                            ->label('Título')
                            ->size('lg')
                            ->weight('bold')
                            ->columnSpanFull(),

                        TextEntry::make('description')
                            ->label('Descripción')
                            ->placeholder('Sin descripción')
                            ->columnSpanFull(),

                    ]),

                Section::make('Ítems')
                    ->schema([
                        RepeatableEntry::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make('Concepto'),
                                TableColumn::make('Cantidad'),
                                TableColumn::make('Precio unitario'),
                                TableColumn::make('Subtotal'),
                            ])
                            ->schema([
                                TextEntry::make('concept')->belowContent(fn ($record) => $record?->description),
                                TextEntry::make('quantity')->numeric(decimalPlaces: 2, locale: 'es_AR'),
                                TextEntry::make('unit_price')->money('ARS', locale: 'es_AR'),
                                TextEntry::make('subtotal')->money('ARS', locale: 'es_AR'),
                            ]),
                    ]),

                Section::make('Condiciones y observaciones')
                    ->columns(2)
                    ->visible(fn (Quote $record) => filled($record->conditions) || filled($record->notes))
                    ->schema([
                        TextEntry::make('conditions')->label('Condiciones')->placeholder('—'),
                        TextEntry::make('notes')->label('Observaciones')->placeholder('—'),
                    ]),

                Grid::make(2)
                    ->schema([

                        Section::make('💰 Información comercial')
                            ->icon('heroicon-o-banknotes')
                            ->schema([

                                TextEntry::make('amount')
                                    ->label('Total')
                                    ->money('ARS')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge()
                                    ->state(fn (Quote $record) => $record->displayStatus())
                                    ->formatStateUsing(fn (Quote $record) => $record->displayStatusLabel())
                                    ->color(fn (string $state) => Quote::STATUS_COLORS[$state] ?? 'gray'),

                                TextEntry::make('issued_at')->label('Fecha')->date('d/m/Y')->placeholder('—'),
                                TextEntry::make('valid_until')->label('Válido hasta')->date('d/m/Y')->placeholder('Sin vencimiento'),

                                TextEntry::make('priority')
                                    ->label('Prioridad')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => match ($state) {
                                        'low' => '🟢 Baja',
                                        'normal' => '🔵 Normal',
                                        'high' => '🟠 Alta',
                                        'urgent' => '🔴 Urgente',
                                        default => $state,
                                    })
                                    ->color(fn (string $state) => match ($state) {
                                        'low' => 'gray',
                                        'normal' => 'info',
                                        'high' => 'warning',
                                        'urgent' => 'danger',
                                        default => 'gray',
                                    }),

                            ]),

                        Section::make('🏢 Datos del cliente')
                            ->icon('heroicon-o-building-office')
                            ->schema([

                                TextEntry::make('client.name')
                                    ->label('Cliente')
                                    ->placeholder('Sin cliente'),

                                TextEntry::make('building.name')
                                    ->label('Edificio')
                                    ->placeholder('Sin edificio'),

                                TextEntry::make('unit')
                                    ->label('Equipo')
                                    ->placeholder('Todo el edificio'),

                                TextEntry::make('created_at')
                                    ->label('Fecha de creación')
                                    ->dateTime('d/m/Y H:i'),

                            ]),

                    ]),

            ]);
    }
}
