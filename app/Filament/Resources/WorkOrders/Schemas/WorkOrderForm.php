<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use Filament\Forms;
use Filament\Schemas\Schema;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use App\Models\Building;

class WorkOrderForm
{
    public static function configure(
        Schema $schema
    ): Schema {

        return $schema
            ->components([

                Forms\Components\Select::make('building_id')
                    ->label('Edificio')
                    ->relationship(
                        name: 'building',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('company_id', Auth::user()->company_id)
                            ->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Building $record) => "{$record->name} {$record->address}"
                    )
                    ->searchable(['name', 'address'])
                    ->preload()
                    ->live()
                    ->afterStateUpdated(fn (callable $set) => $set('unit', null))
                    ->required(),

                Forms\Components\Select::make('unit')
                    ->options(function (callable $get) {

                        $buildingId = $get('building_id');

                        if (!$buildingId) {
                            return [];
                        }

                        $building = Building::query()
                            ->whereKey($buildingId)
                            ->where('company_id', Auth::user()->company_id)
                            ->first();

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

                Forms\Components\Select::make('users')
                    ->label('Técnicos asignados')
                    ->multiple()
                    ->relationship(
                        name: 'users',
                        titleAttribute: 'name',
                        modifyQueryUsing: fn ($query) => $query
                            ->where('company_id', Auth::user()->company_id)
                            ->where('role', 'technician')
                            ->orderBy('name'),
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

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
