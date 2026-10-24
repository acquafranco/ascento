<?php

namespace App\Filament\Resources\WorkOrders\Schemas;

use App\Enums\PlanFeature;
use App\Filament\Support\JobDetail;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\SiteVisit;
use App\Models\WorkOrder;
use App\Services\Visits\WorkPhotoGallery;
use App\Services\WorkOrders\AttendanceTimes;
use App\Support\ElevatorComponents;
use App\Support\Plans\PlanGuard;
use App\Support\WorkOrderLabels;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Set;
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

        // Jerarquía: a la izquierda lo que define el trabajo (edificio y equipo,
        // tipo y descripción); a la derecha cómo está (estado, técnicos, aviso).
        // En el celular, una sola columna en ese mismo orden. Abajo, lo que
        // genera el técnico (tiempos) y las fotos. Mismos campos y reglas de siempre.
        return $schema
            ->components([
                Grid::make(['default' => 1, 'lg' => 3])
                    ->columnSpanFull()
                    ->schema([
                        Group::make([
                            Section::make('Edificio y equipo')
                                ->compact()
                                ->columns(['default' => 1, 'md' => 2])
                                ->schema([
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
                                        ->required()
                                        ->columnSpanFull(),

                                    static::unitField(),

                                    Forms\Components\Select::make('component')
                                        ->label('Componente afectado (opcional)')
                                        ->options(ElevatorComponents::LIST)
                                        ->helperText('Para reclamos: sirve para detectar fallas que se repiten.'),
                                ]),

                            Section::make('Trabajo')
                                ->compact()
                                ->columns(['default' => 1, 'md' => 2])
                                ->schema([
                                    static::typeField(),

                                    static::priorityField(),

                                    static::notesField()->columnSpanFull(),
                                ]),
                        ])->columnSpan(['default' => 1, 'lg' => 2]),

                        Group::make([
                            Section::make('Estado')
                                ->compact()
                                ->schema([static::statusField()]),

                            Section::make('Asignación')
                                ->compact()
                                ->schema([
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
                                ]),

                            static::callReceivedSection(),
                        ])->columnSpan(['default' => 1, 'lg' => 1]),
                    ]),

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
     * Estado de la orden: los mismos cuatro valores y reglas de siempre, como
     * botones con color, icono y texto (el texto se ve siempre).
     */
    public static function statusField(): Forms\Components\ToggleButtons
    {
        return Forms\Components\ToggleButtons::make('status')
            ->hiddenLabel()
            ->options(WorkOrderLabels::STATUSES)
            ->colors(WorkOrderLabels::STATUS_COLORS)
            ->icons(WorkOrderLabels::STATUS_ICONS)
            ->inline()
            ->default('pending')
            ->required();
    }

    /**
     * «Aviso recibido»: lo único de los tiempos que carga la oficina. No se
     * deduce de cuándo se creó la orden; vacío = sin registrar. Se guarda aparte
     * (AttendanceTimes::recordCall, en Create/EditWorkOrder) con quién lo cargó.
     * La llegada y la salida las registra el técnico: acá no se cargan.
     */
    public static function callReceivedSection(): Section
    {
        return Section::make('Aviso recibido')
            ->compact()
            ->schema([
                Forms\Components\DateTimePicker::make('call_received_at')
                    ->label('¿Cuándo recibiste el aviso?')
                    ->placeholder('Sin registrar')
                    ->prefixIcon('heroicon-o-phone-arrow-down-left')
                    ->seconds(false)
                    ->native(false)
                    ->displayFormat('d/m/Y H:i')
                    ->firstDayOfWeek(1)
                    ->maxDate(now()->addMinute())
                    ->hintAction(Action::make('callNow')->label('Ahora')
                        ->action(fn (Set $set) => $set('call_received_at', now()->format('Y-m-d H:i'))))
                    ->helperText(fn (?WorkOrder $record) => static::callHelp($record))
                    ->dehydrated(false)
                    ->rule(fn (?WorkOrder $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        try {
                            AttendanceTimes::validateCall(filled($value) ? Carbon::parse($value, config('app.timezone')) : null, $record);
                        } catch (ValidationException $e) {
                            $fail(collect($e->errors())->flatten()->first());
                        }
                    }),
            ]);
    }

    /** Para qué sirve y, si ya se cargó, de dónde salió y quién lo cargó. */
    private static function callHelp(?WorkOrder $record): string
    {
        $base = 'Sirve para calcular el tiempo de respuesta. Si no sabés la hora, dejalo vacío.';
        $times = $record?->call_received_at ? AttendanceTimes::summary($record) : null;

        return $times && $times['call_source']
            ? ucfirst($times['call_source']).($times['call_by'] ? ' · '.$times['call_by'] : '').'. '.$base
            : $base;
    }

    /**
     * Tiempos de atención (solo lectura, en una orden existente): aviso,
     * llegada y salida reales y las tres duraciones. Distingue «Pendiente» (todavía
     * puede pasar) de «Sin registrar» (no se registró y ya no va a llegar). Nunca
     * muestra una duración si falta un horario (AttendanceTimes).
     */
    public static function attentionTimesSection(): Section
    {
        return Section::make('Tiempos de atención')
            ->compact()
            ->columnSpanFull()
            ->visible(fn (?WorkOrder $record) => $record !== null)
            ->schema([
                View::make('filament.partials.attention-times')
                    ->viewData(fn (?WorkOrder $record) => $record ? static::attentionTimes($record) : []),
            ]);
    }

    /**
     * Lo que muestra «Tiempos de atención».
     *
     * @return array<string, mixed>
     */
    public static function attentionTimes(WorkOrder $record): array
    {
        $record->loadMissing(['arrivedBy', 'leftBy', 'callRecordedBy']);
        $t = AttendanceTimes::summary($record);
        $open = in_array($record->status, ['pending', 'in_progress'], true) && ! $record->deliveryNote()->exists();
        $visitOpen = $record->site_visit_id && SiteVisit::whereKey($record->site_visit_id)->whereNull('closed_at')->exists();
        $arrivalPending = $t['arrived'] === null && $open;
        $exitPending = $t['left'] === null && $t['arrived'] !== null && ($open || $visitOpen);
        // Duración: un número solo con los dos horarios; si no, «Pendiente» (aún puede calcularse) o «Sin registrar».
        $duration = fn (?int $minutes, bool $pending) => [
            'value' => $minutes === null ? ($pending ? 'Pendiente' : 'Sin registrar') : AttendanceTimes::duration($minutes),
            'state' => $minutes === null ? ($pending ? 'pending' : 'missing') : 'ok',
        ];

        return [
            'call' => ['at' => $t['call'], 'by' => trim(($t['call_source'] ? ucfirst($t['call_source']) : '').($t['call_by'] ? ' · '.$t['call_by'] : ''), ' ·')],
            'arrived' => ['at' => $t['arrived'], 'by' => $t['arrived_by'], 'state' => $t['arrived'] ? 'ok' : ($arrivalPending ? 'pending' : 'missing'),
                'label' => $t['arrived'] ? AttendanceTimes::time($t['arrived']) : ($arrivalPending ? 'Pendiente de llegada' : 'Sin registrar')],
            'left' => ['at' => $t['left'], 'by' => $t['left_by'], 'state' => $t['left'] ? 'ok' : ($exitPending || $arrivalPending ? 'pending' : 'missing'),
                'label' => $t['left'] ? AttendanceTimes::time($t['left']) : ($exitPending || $arrivalPending ? 'Pendiente de salida' : 'Sin registrar')],
            'technicianStarted' => $t['arrived'] !== null || $t['left'] !== null,
            'response' => $duration($t['response'], $t['call'] !== null && $arrivalPending),
            'on_site' => $duration($t['on_site'], $exitPending || $arrivalPending),
            'total' => $duration($t['total'], $t['call'] !== null && ($exitPending || $arrivalPending)),
            'visit' => static::siteVisitSummary($record),
        ];
    }

    /**
     * «Juan · llegada 10/03 09:12 · salida 10/03 11:40 · 3 órdenes, 2 remitos», el
     * cierre sin salida con su motivo y, si el remito se firmó sin llegada, quién,
     * cuándo y por qué.
     */
    public static function siteVisitSummary(?WorkOrder $record): string
    {
        $visit = $record?->site_visit_id ? SiteVisit::with(['user', 'closedBy'])->withCount(['workOrders', 'buildingVisits'])->find($record->site_visit_id) : null;
        $remito = $record ? BuildingVisit::with('user')->where('work_order_id', $record->id)->whereNotNull('arrival_exception')->first() : null;
        $exception = $remito ? ' Remito sin llegada registrada ('.($remito->user?->name ?? 'técnico').', '.AttendanceTimes::time($remito->created_at).'): '.$remito->arrival_exception : '';

        if (! $visit) {
            return ($record?->arrived_at ? 'Llegada registrada en la orden (antes de las visitas técnicas).' : 'Sin visita registrada.').$exception;
        }
        $text = ($visit->user?->name ?? 'Técnico').' · llegada '.AttendanceTimes::time($visit->arrived_at).' · salida '.AttendanceTimes::time($visit->left_at)
            .' · '.$visit->work_orders_count.' '.($visit->work_orders_count === 1 ? 'orden' : 'órdenes').', '.$visit->building_visits_count.' '.($visit->building_visits_count === 1 ? 'remito' : 'remitos');

        return match (true) {
            $visit->closed_at === null => $text.' · en curso',
            $visit->left_at === null => $text.' · cerrada sin salida por '.($visit->closedBy?->name ?? '—').' ('.AttendanceTimes::time($visit->closed_at).'): '.$visit->close_note,
            default => $text,
        }.$exception;
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
