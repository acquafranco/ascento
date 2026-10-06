<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Models\DeliveryNote;
use App\Support\WorkOrderLabels;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Forms\Components\View;
use Filament\Forms\Components\TextInput;

class DeliveryNoteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema

            ->columns(12)

            ->components([

                Section::make('Información general')
                    ->columnSpanFull()
                    ->schema([

                    Grid::make('1')
                        ->columnSpanFull()
                        ->schema([

                          TextInput::make('cliente')
                            ->label('Cliente')
                            ->disabled()
                            ->dehydrated(false)
                            ->afterStateHydrated(function ($component, $record) {
                                $component->state(
                                    $record?->building?->client?->name
                                );
                            })

                        ]),

                        Grid::make(12)
                            ->schema([

                                // Selects de solo lectura: basta con la opción del propio
                                // remito (antes se cargaban todos los edificios, órdenes y
                                // usuarios en cada vista).
                                Select::make('building_id')
                                    ->label('Edificio')
                                    ->disabled()
                                    ->columnSpan(6)
                                    ->options(fn (?DeliveryNote $record) => $record?->building
                                        ? [$record->building_id => "{$record->building->name} - {$record->building->address}"]
                                        : []),

                                Select::make('work_order_id')
                                    ->label('Orden de trabajo')
                                    ->disabled()
                                    ->columnSpan(6)
                                    ->options(fn (?DeliveryNote $record) => $record?->workOrder
                                        ? [$record->work_order_id => WorkOrderLabels::type($record->workOrder->type)]
                                        : []),

                                Select::make('user_id')
                                    ->label('Técnico')
                                    ->disabled()
                                    ->columnSpan(4)
                                    ->options(fn (?DeliveryNote $record) => $record?->user
                                        ? [$record->user_id => $record->user->name]
                                        : []),

                                Select::make('month')
                                    ->label('Mes')
                                    ->required()
                                    ->columnSpan(4)
                                    ->options([
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
                                    ]),

                                TextInput::make('year')
                                    ->label('Año')
                                    ->numeric()
                                    ->required()
                                    ->columnSpan(4),
                            ]),
                    ]),

                Section::make('Equipos')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(2)
                            ->schema([

                                TextInput::make('elevator_quantity')
                                    ->label('Ascensores')
                                    ->numeric()
                                    ->required(),

                                TextInput::make('freight_elevator_quantity')
                                    ->label('Montacargas')
                                    ->numeric()
                                    ->required(),
                            ]),
                    ]),

                Section::make('Trabajo realizado')
                    ->columnSpanFull()
                    ->schema([

                        Textarea::make('description')
                            ->label('Descripción')
                            ->rows(8)
                            ->required(),

                        Toggle::make('performed')
                            ->label('Trabajo realizado'),
                    ]),



                Section::make('Datos de aclaraciones')
                    ->columnSpanFull()
                    ->schema([

                        Grid::make(2)
    ->schema([

        TextInput::make('signature_name')
            ->label('Aclaración técnico')
            ->disabled(),

        TextInput::make('client_signature_name')
            ->label('Aclaración cliente')
            ->disabled(),
    ])

                    ]),
            ]);
    }
}
