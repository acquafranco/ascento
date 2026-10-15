<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Filament\Support\JobDetail;
use App\Models\Building;
use App\Models\WorkOrder;
use App\Services\Visits\WorkPhotoGallery;
use App\Support\ElevatorComponents;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
                        modifyQueryUsing: function ($query) {
                            $user = Auth::user();

                            $companyId = $user->isSuperAdmin()
                                ? session('selected_company_id')
                                : $user->company_id;

                            return $query
                                ->withoutTrashed()
                                ->where('company_id', $companyId)
                                ->orderBy('name');
                        },
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

                        if (! $buildingId) {
                            return [];
                        }

                        $user = Auth::user();

                        $companyId = $user->isSuperAdmin()
                            ? session('selected_company_id')
                            : $user->company_id;

                        $building = Building::query()
                            ->whereKey($buildingId)
                            ->where('company_id', $companyId)
                            ->first();

                        if (! $building) {
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

                Forms\Components\Select::make('component')
                    ->label('Componente afectado (opcional)')
                    ->options(ElevatorComponents::LIST)
                    ->helperText('Para reclamos: sirve para detectar fallas que se repiten.'),

                Forms\Components\Select::make('users')
                    ->label('Técnicos asignados')
                    ->multiple()
                    ->relationship(
                        name: 'users',
                        titleAttribute: 'name',
                        modifyQueryUsing: function ($query) {
                            $user = Auth::user();

                            $companyId = $user->isSuperAdmin()
                                ? session('selected_company_id')
                                : $user->company_id;

                            return $query
                                ->withoutTrashed()
                                ->where('company_id', $companyId)
                                ->where('role', 'technician')
                                ->orderBy('name');
                        },
                    )
                    ->searchable()
                    ->preload()
                    ->required(),

                // Guardia de origen (opcional): la elige quien carga la orden; nunca se
                // deduce por la hora. Turnos vigentes o de los últimos 3 días.
                Forms\Components\Select::make('on_call_shift_id')
                    ->label('Guardia (si se originó durante una)')
                    ->placeholder('No es un trabajo de guardia')
                    ->options(fn (?WorkOrder $record) => OnCallShiftOptions::for($record))
                    ->searchable()
                    ->helperText(fn () => OnCallShiftOptions::onDutyNow())
                    ->disabled(fn (?WorkOrder $record) => $record?->deliveryNote()->exists() ?? false),

                Forms\Components\Select::make(
                    'type'
                )
                    ->options([
                        'maintenance' => 'Mantenimiento',

                        'inspection' => 'Inspección',

                        'claim' => 'Reclamo',

                        'installation' => 'Instalación',

                        'modernization' => 'Modernización',
                    ])
                    ->required()
                    ->label('Tipo'),

                Forms\Components\Select::make(
                    'priority'
                )
                    ->options([
                        'low' => 'Baja',

                        'medium' => 'Media',

                        'high' => 'Alta',

                        'urgent' => 'Urgente',
                    ])
                    ->default('medium')
                    ->required()
                    ->label('Prioridad'),

                Forms\Components\Select::make(
                    'status'
                )
                    ->options([
                        'pending' => 'Pendiente',

                        'in_progress' => 'En progreso',

                        'completed' => 'Completado',

                        'failed' => 'No realizado',
                    ])
                    ->default('pending')
                    ->required(),

                Forms\Components\Textarea::make(
                    'notes'
                )
                    ->columnSpanFull()
                    ->label('Detalle'),

                // Fotos de antes y después de ESTA orden (solo lectura; las
                // carga el técnico desde la orden en su celular).
                Section::make('Fotos del trabajo')
                    ->columnSpanFull()
                    ->visible(fn (?WorkOrder $record) => $record !== null)
                    ->schema([
                        // Exclusivas del plan Empresa: con otro plan, el aviso (las fotos se conservan).
                        View::make('filament.partials.work-photos')
                            ->visible(fn (?WorkOrder $record) => JobDetail::hasPlan($record))
                            ->viewData(fn (?WorkOrder $record) => [
                                'photos' => $record ? app(WorkPhotoGallery::class)->forWorkOrder($record, Auth::user()) : collect(),
                                'emptyText' => 'Esta orden no tiene fotos.',
                            ]),
                        View::make('filament.partials.work-photos-locked')
                            ->visible(fn (?WorkOrder $record) => ! JobDetail::hasPlan($record)),
                    ]),
            ]);
    }
}
