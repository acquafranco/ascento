<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;

class WorkOrderForm
{
    public static function configure(
        Schema $schema
    ): Schema {

        return $schema
            ->components([

                Forms\Components\Select::make(
                    'building_id'
                )
                    ->relationship(
                        name: 'building',
                        titleAttribute: 'name'
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn ($record) =>
                            "{$record->name} {$record->address}"
                    )
                    ->searchable()
                    ->preload()
                    ->live()
                    ->required()
                    ->label('Edificio'),

                Forms\Components\Select::make('unit')
                    ->options(function (callable $get) {

                        $buildingId = $get('building_id');

                        if (!$buildingId) {
                            return [];
                        }

                        $building = \App\Models\Building::find(
                            $buildingId
                        );

                        if (!$building) {
                            return [];
                        }

                        $options = [];

                        /*
                        |--------------------------------------------------------------------------
                        | ASCENSORES
                        |--------------------------------------------------------------------------
                        */

                        if ($building->elevator_count > 0) {

                            $elevators = [];

                            for (
                                $i = 1;
                                $i <= $building->elevator_count;
                                $i++
                            ) {
                                $elevators[
                                    "Ascensor {$i}"
                                ] = "Ascensor {$i}";
                            }

                            $options['🏢 Ascensores'] =
                                $elevators;
                        }

                        /*
                        |--------------------------------------------------------------------------
                        | MONTACARGAS
                        |--------------------------------------------------------------------------
                        */

                        if (
                            $building->freight_elevator_count > 0
                        ) {

                            $freight = [];

                            for (
                                $i = 1;
                                $i <= $building->freight_elevator_count;
                                $i++
                            ) {
                                $freight[
                                    "Montacargas {$i}"
                                ] =
                                    "Montacargas {$i}";
                            }

                            $options['📦 Montacargas'] =
                                $freight;
                        }

                        return $options;
                    })
                    ->searchable()
                    ->placeholder('Elegí edificio primero')
                    ->required()
                    ->label('Unidad'),

                Forms\Components\Select::make(
                    'user_id'
                )
                    ->relationship(
                        'technician',
                        'name'
                    )
                    ->searchable()
                    ->preload()
                    ->required()
                    ->label('Técnico'),

                Forms\Components\Select::make(
                    'type'
                )
                    ->options([
                        'maintenance' =>
                            'Mantenimiento',

                        'inspection' =>
                            'Inspección',

                        'claim' =>
                            'Reclamo',

                        'installation' =>
                            'Instalación',

                        'modernization' =>
                            'Modernización',
                    ])
                    ->required()
                    ->label('Tipo'),

                Forms\Components\Select::make(
                    'priority'
                )
                    ->options([
                        'low' =>
                            'Baja',

                        'medium' =>
                            'Media',

                        'high' =>
                            'Alta',

                        'urgent' =>
                            'Urgente',
                    ])
                    ->default('medium')
                    ->required()
                    ->label('Prioridad'),

                Forms\Components\Select::make(
                    'status'
                )
                    ->options([
                        'pending' =>
                            'Pendiente',

                        'in_progress' =>
                            'En progreso',

                        'completed' =>
                            'Completado',

                        'failed' =>
                            'No realizado',
                    ])
                    ->default('pending')
                    ->required(),

                Forms\Components\Textarea::make(
                    'notes'
                )
                    ->columnSpanFull()
                    ->label('Detalle'),
            ]);
    }
}
