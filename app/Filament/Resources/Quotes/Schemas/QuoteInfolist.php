<?php

namespace App\Filament\Resources\Quotes\Schemas;

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

                Grid::make(2)
                    ->schema([

                        Section::make('💰 Información comercial')
                            ->icon('heroicon-o-banknotes')
                            ->schema([

                                TextEntry::make('amount')
                                    ->label('Importe')
                                    ->money('ARS')
                                    ->size('lg')
                                    ->weight('bold')
                                    ->color('success'),

                                TextEntry::make('status')
                                    ->label('Estado')
                                    ->badge()
                                    ->formatStateUsing(fn (string $state) => match ($state) {
                                        'pending' => 'Pendiente',
                                        'sent' => 'Enviado',
                                        'approved' => 'Aprobado',
                                        'rejected' => 'Rechazado',
                                        default => $state,
                                    })
                                    ->color(fn (string $state) => match ($state) {
                                        'pending' => 'warning',
                                        'sent' => 'info',
                                        'approved' => 'success',
                                        'rejected' => 'danger',
                                        default => 'gray',
                                    }),

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

                                TextEntry::make('created_at')
                                    ->label('Fecha de creación')
                                    ->dateTime('d/m/Y H:i'),

                            ]),

                    ]),

            ]);
    }
}
