<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use Filament\Infolists\Infolist;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\Grid;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;

class DeliveryNoteInfolist
{
    public static function configure(Infolist $infolist): Infolist
    {
        return $infolist
            ->schema([

                // 🧑 CLIENTE ARRIBA DE TODO
                Section::make('Cliente')
                    ->schema([
                        TextEntry::make('building.client.name')
                            ->label('Cliente'),
                    ]),

                // 🏢 INFO GENERAL
                Section::make('Información general')
                    ->schema([
                        Grid::make(2)
                            ->schema([

                                TextEntry::make('number')
                                    ->label('N° Remito'),

                                TextEntry::make('building.full_name')
                                    ->label('Edificio'),

                                TextEntry::make('workOrder.type')
                                    ->label('Trabajo')
                                    ->formatStateUsing(fn ($state) => match ($state) {
                                        'maintenance' => 'Mantenimiento',
                                        'inspection' => 'Inspección',
                                        'claim' => 'Reclamo',
                                        'installation' => 'Instalación',
                                        'modernization' => 'Modernización',
                                        default => $state,
                                    }),

                                TextEntry::make('user.name')
                                    ->label('Técnico'),

                                TextEntry::make('created_at')
                                    ->label('Fecha')
                                    ->dateTime('d/m/Y H:i'),
                            ]),
                    ]),

                // ⚙️ EQUIPOS (todo más compacto)
                Section::make('Equipos')
                    ->schema([
                        Grid::make(3) // 👈 IMPORTANTE: 3 columnas como querías
                            ->schema([

                                TextEntry::make('elevator_quantity')
                                    ->label('Ascensores'),

                                TextEntry::make('freight_elevator_quantity')
                                    ->label('Montacargas'),

                                TextEntry::make('month')
                                    ->label('Mes')
                                    ->formatStateUsing(fn ($state) => [
                                        1 => 'Enero',
                                        2 => 'Febrero',
                                        3 => 'Marzo',
                                        4 => 'Abril',
                                        5 => 'Mayo',
                                        6 => 'Junio',
                                        7 => 'Julio',
                                        8 => 'Agosto',
                                        9 => 'Septiembre',
                                        10 => 'Octubre',
                                        11 => 'Noviembre',
                                        12 => 'Diciembre',
                                    ][$state] ?? $state),

                                TextEntry::make('year')
                                    ->label('Año'),
                            ]),
                    ]),

                // 📝 TRABAJO
                Section::make('Trabajo realizado')
                    ->schema([
                        TextEntry::make('description')
                            ->label('Descripción')
                            ->columnSpanFull(),

                        IconEntry::make('performed')
                            ->label('Realizado')
                            ->boolean(),
                    ]),

                // ✍️ FIRMAS (IMPORTANTE: imagen real)
                Section::make('Firmas')
                    ->schema([
                        Grid::make(2)
                            ->schema([

                                ImageEntry::make('signature')
                                    ->label('Firma técnico')
                                    ->height(120),

                                ImageEntry::make('client_signature')
                                    ->label('Firma cliente')
                                    ->height(120),
                            ]),

                        Grid::make(2)
                            ->schema([

                                TextEntry::make('signature_name')
                                    ->label('Aclaración técnico'),

                                TextEntry::make('client_signature_name')
                                    ->label('Aclaración cliente'),
                            ]),
                    ]),

            ]);
    }
}
