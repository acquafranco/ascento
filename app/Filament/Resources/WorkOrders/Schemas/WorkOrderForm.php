<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\PlanFeature;
use App\Filament\Support\JobDetail;
use App\Models\Building;
use App\Models\WorkOrder;
use App\Services\Visits\WorkPhotoGallery;
use App\Services\WorkOrders\AttendanceTimes;
use App\Support\ElevatorComponents;
use App\Support\Plans\PlanGuard;
use App\Support\WorkOrderLabels;
use Filament\Forms;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

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

                static::unitField(),

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
                    ->disabled(fn (?WorkOrder $record) => $record?->deliveryNote()->exists() ?? false)
                    ->visible(fn () => PlanGuard::currentAllows(PlanFeature::OnCall)),

                static::typeField(),

                static::priorityField(),

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

                static::notesField(),

                static::attentionTimesSection(),

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

    /*
    |--------------------------------------------------------------------------
    | CAMPOS COMPARTIDOS con la configuración inicial guiada (CompanySetupWizard)
    |--------------------------------------------------------------------------
    */

    /** Ascensor o montacargas del edificio elegido (building_id). */
    public static function unitField(): Forms\Components\Select
    {
        return Forms\Components\Select::make('unit')
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
            ->label('Unidad');
    }

    /**
     * Tiempos de atención: la hora del AVISO la carga la oficina (no se deduce de
     * cuándo se creó la orden); LLEGADA y SALIDA las registra el técnico desde su
     * celular con la hora del servidor y acá solo se ven (AttendanceTimes).
     */
    public static function attentionTimesSection(): Section
    {
        $times = fn (?WorkOrder $record) => $record ? AttendanceTimes::summary($record) : null;

        return Section::make('Tiempos de atención')
            ->description('Aviso, llegada al edificio y salida. Las duraciones se calculan solo con los dos horarios necesarios; si falta uno, «Sin registrar».')
            ->columnSpanFull()
            ->columns(['default' => 1, 'md' => 3])
            ->schema([
                Forms\Components\DateTimePicker::make('call_received_at')
                    ->label('Aviso recibido')
                    ->seconds(false)
                    ->native(false)
                    ->maxDate(now()->addMinute())
                    ->helperText('Cuándo llegó el reclamo o la llamada (no cuándo se cargó la orden). Vacío: sin registrar.')
                    // Se guarda aparte (AttendanceTimes::recordCall) con quién lo cargó.
                    ->dehydrated(false)
                    ->rule(fn (?WorkOrder $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        try {
                            AttendanceTimes::validateCall(filled($value) ? Carbon::parse($value, config('app.timezone')) : null, $record);
                        } catch (ValidationException $e) {
                            $fail(collect($e->errors())->flatten()->first());
                        }
                    }),
                TextEntry::make('attention_arrived')->label('Llegada al edificio')
                    ->state(fn (?WorkOrder $record) => $record ? AttendanceTimes::time($record->arrived_at).($record->arrivedBy ? ' · '.$record->arrivedBy->name : '') : 'Sin registrar'),
                TextEntry::make('attention_left')->label('Salida')
                    ->state(fn (?WorkOrder $record) => $record ? AttendanceTimes::time($record->left_at).($record->leftBy ? ' · '.$record->leftBy->name : '') : 'Sin registrar'),
                TextEntry::make('attention_call_source')->label('Origen del aviso')
                    ->state(fn (?WorkOrder $record) => ($t = $times($record)) && $t['call_source'] ? ucfirst($t['call_source']).($t['call_by'] ? ' · '.$t['call_by'] : '') : 'Sin registrar'),
                TextEntry::make('attention_response')->label('Tiempo de respuesta')
                    ->state(fn (?WorkOrder $record) => AttendanceTimes::duration($times($record)['response'] ?? null)),
                TextEntry::make('attention_on_site')->label('Tiempo en el lugar')
                    ->state(fn (?WorkOrder $record) => AttendanceTimes::duration($times($record)['on_site'] ?? null)),
                TextEntry::make('attention_total')->label('Tiempo total')
                    ->state(fn (?WorkOrder $record) => AttendanceTimes::duration($times($record)['total'] ?? null)),
                // Visita técnica (un técnico en el edificio): la llegada y la salida de arriba son las de la visita.
                TextEntry::make('attention_site_visit')->label('Visita técnica')
                    ->columnSpanFull()
                    ->state(fn (?WorkOrder $record) => static::siteVisitSummary($record)),
            ]);
    }

    /** «Llegada 10/03 09:12 · salida 10/03 11:40 · 3 órdenes, 2 remitos», o el cierre sin salida con su motivo. */
    public static function siteVisitSummary(?WorkOrder $record): string
    {
        $visit = $record?->site_visit_id ? \App\Models\SiteVisit::with(['user', 'closedBy'])->withCount(['workOrders', 'buildingVisits'])->find($record->site_visit_id) : null;
        if (! $visit) {
            return $record?->arrived_at ? 'Llegada registrada en la orden (antes de las visitas técnicas).' : 'Sin visita registrada.';
        }
        $text = ($visit->user?->name ?? 'Técnico').' · llegada '.AttendanceTimes::time($visit->arrived_at).' · salida '.AttendanceTimes::time($visit->left_at)
            .' · '.$visit->work_orders_count.' '.($visit->work_orders_count === 1 ? 'orden' : 'órdenes').', '.$visit->building_visits_count.' '.($visit->building_visits_count === 1 ? 'remito' : 'remitos');

        return match (true) {
            $visit->closed_at === null => $text.' · en curso',
            $visit->left_at === null => $text.' · cerrada sin salida por '.($visit->closedBy?->name ?? '—').' ('.AttendanceTimes::time($visit->closed_at).'): '.$visit->close_note,
            default => $text,
        };
    }

    public static function typeField(): Forms\Components\Select
    {
        return Forms\Components\Select::make(
            'type'
        )
            // Lista única de tipos (WorkOrderLabels::TYPES), validada también en el servidor.
            ->options(WorkOrderLabels::TYPES)
            ->in(array_keys(WorkOrderLabels::TYPES))
            ->required()
            ->helperText('Reclamo: un aviso que necesita atención. Reparación: un trabajo hecho (puede llevar repuestos y facturarse aparte).')
            ->label('Tipo');
    }

    public static function priorityField(): Forms\Components\Select
    {
        return Forms\Components\Select::make(
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
            ->label('Prioridad');
    }

    public static function notesField(): Forms\Components\Textarea
    {
        return Forms\Components\Textarea::make(
            'notes'
        )
            ->columnSpanFull()
            ->label('Detalle');
    }
}
