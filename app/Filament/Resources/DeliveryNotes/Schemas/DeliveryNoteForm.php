<?php

namespace App\Filament\Resources\DeliveryNotes\Schemas;

use App\Models\Building;
use App\Models\User;
use App\Models\WorkOrder;
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

                                Select::make('building_id')
                                    ->label('Edificio')
                                    ->disabled()
                                    ->columnSpan(6)
                                    ->options(
                                        Building::query()
                                            ->orderBy('name')
                                            ->get()
                                            ->mapWithKeys(fn ($building) => [
                                                $building->id => "{$building->name} - {$building->address}",
                                            ])
                                    ),

                                Select::make('work_order_id')
                                    ->label('Orden de trabajo')
                                    ->disabled()
                                    ->columnSpan(6)
                                    ->options(
                                        WorkOrder::query()
                                            ->get()
                                            ->mapWithKeys(fn ($wo) => [
                                                $wo->id => match ($wo->type) {
                                                    'maintenance' => 'Mantenimiento',
                                                    'inspection' => 'Inspección',
                                                    'claim' => 'Reclamo',
                                                    'installation' => 'Instalación',
                                                    'modernization' => 'Modernización',
                                                    default => $wo->type,
                                                },
                                            ])
                                    ),

                                Select::make('user_id')
                                    ->label('Técnico')
                                    ->disabled()
                                    ->columnSpan(4)
                                    ->options(
                                        User::query()
                                            ->orderBy('name')
                                            ->pluck('name', 'id')
                                    ),

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
