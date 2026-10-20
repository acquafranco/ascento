<?php

namespace App\Filament\Resources\OnCall;

use App\Enums\PermissionArea;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Concerns\ScopedToCurrentCompany;
use App\Filament\Resources\OnCall\Pages\CreateOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\EditOnCallSchedule;
use App\Filament\Resources\OnCall\Pages\ListOnCallSchedules;
use App\Filament\Resources\OnCall\Pages\OnCallHome;
use App\Filament\Resources\OnCall\Pages\ViewOnCallSchedule;
use App\Filament\Resources\OnCall\RelationManagers\ChangesRelationManager;
use App\Filament\Resources\OnCall\RelationManagers\ShiftsRelationManager;
use App\Filament\Resources\OnCall\RelationManagers\WorkOrdersRelationManager;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\OnCallText;
use App\Services\OnCall\RotationService;
use App\Support\CompanyContext;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * Guardias técnicas: cobertura fuera del horario habitual. La configuración
 * (días, horario, cuántos técnicos a la vez, manual o rotativa) está acá; las
 * asignaciones efectivas y su historial, en sus turnos (OnCallService).
 */
class OnCallScheduleResource extends Resource
{
    use GuardedByPermission;

    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::OnCall;
    }

    use ScopedToCurrentCompany;

    protected static ?string $model = OnCallSchedule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-phone';

    protected static ?string $navigationLabel = 'Guardias';

    protected static ?string $modelLabel = 'guardia';

    protected static ?string $pluralModelLabel = 'Guardias';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'guardias';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        // Con turnos ya vigentes, el horario no se cambia: el historial depende de él.
        $locked = fn (?OnCallSchedule $record) => $record?->hasStartedShifts() ?? false;
        $rotation = fn (Get $get) => $get('mode') === OnCallSchedule::ROTATION;

        return $schema->components([
            Section::make('Horario de guardia')->columnSpanFull()
                ->description(fn (?OnCallSchedule $record) => $locked($record)
                    ? 'Esta guardia ya tuvo turnos: su horario no se cambia (el historial depende de él). Para otro horario, dejá de usarla y creá una nueva.'
                    : 'Cuándo hace falta alguien de guardia. Abajo elegís quiénes se turnan.')
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(120)->placeholder('Ej: Guardia nocturna, Fines de semana'),
                    CheckboxList::make('days')->label('¿Qué días empieza la guardia?')->options(OnCallSchedule::DAYS)
                        ->columns(['default' => 2, 'sm' => 4, 'lg' => 7])->required()->bulkToggleable()->live()
                        ->default([1, 2, 3, 4, 5])->disabled($locked),
                    Grid::make(['default' => 1, 'sm' => 3])->schema([
                        TimePicker::make('starts_time')->label('¿Desde qué hora?')->seconds(false)->required()->default('18:00')->disabled($locked)->live(onBlur: true),
                        TimePicker::make('ends_time')->label('¿Hasta qué hora?')->seconds(false)->required()->default('08:00')->disabled($locked)->live(onBlur: true)
                            ->helperText('Si es más temprano que el inicio, termina al día siguiente. Igual al inicio = 24 horas.'),
                        TextInput::make('technicians_needed')->label('¿Cuántos técnicos cubren a la vez?')->numeric()->minValue(1)->maxValue(10)->default(1)->required()
                            ->disabled($locked)->helperText('Casi siempre, 1.'),
                    ]),
                    Text::make(fn (Get $get) => 'Así queda: '.self::draft($get, null)->coverageSentence())
                        ->weight('bold')->visible(fn (Get $get) => filled($get('days')) && filled($get('starts_time')) && filled($get('ends_time'))),
                    Textarea::make('notes')->label('Indicaciones para los técnicos (opcional)')->rows(2)->maxLength(2000)
                        ->helperText('Por ejemplo: a qué teléfono llamar o qué edificios priorizar. Lo ven los técnicos asignados.'),
                ]),

            // Lo normal: los técnicos se turnan siempre en el mismo orden y el ciclo
            // se repite solo. Asignar a mano queda para quien no tiene un orden fijo.
            Section::make('¿Quiénes se turnan?')->columnSpanFull()
                ->description('Elegís los técnicos y el orden una sola vez: Ascento repite el ciclo para siempre (Juan → Ana → Luis → Juan…). Las ausencias y los reemplazos se manejan aparte, sin romper el orden.')
                ->schema([
                    Radio::make('mode')->label('¿Cómo se reparten las guardias?')->required()->live()
                        ->default(fn () => OnCallService::technicians()->isNotEmpty() ? OnCallSchedule::ROTATION : OnCallSchedule::MANUAL)
                        ->options([
                            OnCallSchedule::ROTATION => 'Se turnan siempre en el mismo orden (recomendado)',
                            OnCallSchedule::MANUAL => 'Las asigno a mano cada vez',
                        ])
                        ->descriptions([
                            OnCallSchedule::ROTATION => 'Ascento arma los turnos solo y avisa a cada técnico cuándo le toca.',
                            OnCallSchedule::MANUAL => 'Para quien no tiene un orden fijo: asignás cada turno desde «Guardias».',
                        ]),
                    Repeater::make('members')->label('Técnicos, en el orden en que se turnan')->relationship()
                        ->visible($rotation)->live()
                        ->orderColumn('position')->reorderable()->addActionLabel('Agregar técnico')->defaultItems(0)
                        ->minItems(fn (Get $get) => $rotation($get) ? 1 : 0)
                        ->columns(['default' => 1, 'sm' => 3])
                        ->schema([
                            Select::make('user_id')->label('Técnico')->required()->distinct()
                                ->options(fn () => OnCallService::technicians()->pluck('name', 'id')),
                            DatePicker::make('excluded_from')->label('Ausente desde (opcional)'),
                            DatePicker::make('excluded_until')->label('Hasta')->afterOrEqual('excluded_from'),
                        ])
                        ->helperText('El primero cubre el primer turno, después el segundo, y así; al terminar la lista vuelve a empezar. Si alguien está ausente (vacaciones, licencia), se saltea y cubre el siguiente. Arrastrá para cambiar el orden.'),
                    Grid::make(['default' => 1, 'sm' => 3])->visible($rotation)->schema([
                        TextInput::make('rotation_days')->label('¿Cada cuántos días cambia el técnico?')->numeric()->minValue(1)->maxValue(31)->default(7)->live(onBlur: true)
                            ->required($rotation)->helperText('7 = una semana cada uno.'),
                        DatePicker::make('rotation_starts_on')->label('¿Desde qué día?')->default(today())->live(onBlur: true)
                            ->required($rotation)->helperText('El primero de la lista arranca ese día, a la hora de inicio de la guardia.'),
                        DatePicker::make('rotation_ends_on')->label('¿Termina en alguna fecha? (casi nunca)')->live(onBlur: true)
                            ->afterOrEqual('rotation_starts_on')->helperText('Vacío = se repite siempre.'),
                    ]),
                    View::make('filament.on-call.rotation-preview')->visible($rotation)
                        ->viewData(fn (Get $get, ?OnCallSchedule $record) => ['preview' => self::rotationPreview($get, $record), 'endsOn' => filled($get('rotation_ends_on')) ? Carbon::parse($get('rotation_ends_on'))->format('d/m/Y') : null]),
                ]),
        ]);
    }

    /** La guardia como quedaría con los datos del formulario (sin guardar). */
    public static function draft(Get $get, ?OnCallSchedule $record): OnCallSchedule
    {
        $draft = $record ? clone $record : new OnCallSchedule;
        $draft->forceFill([
            'company_id' => $record?->company_id ?? CompanyContext::currentId(),
            'name' => $get('name') ?: ($record?->name ?? 'Guardia'),
            'days' => array_values(array_map('intval', (array) ($get('days') ?? $record?->days ?? []))),
            'starts_time' => OnCallSchedule::normalizeTime($get('starts_time') ?? $record?->starts_time),
            'ends_time' => OnCallSchedule::normalizeTime($get('ends_time') ?? $record?->ends_time),
            'technicians_needed' => max(1, (int) ($get('technicians_needed') ?: 1)),
            'mode' => $get('mode') ?? OnCallSchedule::MANUAL,
            'rotation_days' => filled($get('rotation_days')) ? max(1, (int) $get('rotation_days')) : null,
            'rotation_starts_on' => $get('rotation_starts_on') ?: null,
            'rotation_ends_on' => $get('rotation_ends_on') ?: null,
            'is_active' => $record?->is_active ?? true,
        ]);
        $days = $draft->days;
        sort($days);
        $draft->days = $days;

        return $draft;
    }

    /**
     * Vista previa de la rotación con los datos del formulario: qué falta para
     * poder generarla, los próximos turnos y qué cambia en los ya generados.
     *
     * @return array{missing: list<string>, rows: list<array>, example: ?string, warnings: list<string>, changes: int}
     */
    public static function rotationPreview(Get $get, ?OnCallSchedule $record): array
    {
        $result = ['missing' => [], 'rows' => [], 'example' => null, 'warnings' => [], 'changes' => 0];
        $schedule = self::draft($get, $record);
        $technicians = OnCallService::technicians()->keyBy('id');
        $members = collect($get('members') ?? [])->values()
            ->filter(fn ($m) => filled($m['user_id'] ?? null) && $technicians->has((int) $m['user_id']))
            ->map(function ($m, $i) {
                $member = new OnCallMember;
                $member->forceFill(['user_id' => (int) $m['user_id'], 'position' => $i, 'excluded_from' => $m['excluded_from'] ?? null, 'excluded_until' => $m['excluded_until'] ?? null]);
                $user = User::withoutGlobalScopes()->find((int) $m['user_id']);

                return $member->setRelation('user', $user);
            })->values();

        if ($technicians->isEmpty()) {
            $result['missing'][] = 'No hay técnicos cargados: primero cargalos en «Técnicos».';
        }
        if ($members->isEmpty()) {
            $result['missing'][] = 'Agregá al menos un técnico a la rotación.';
        }
        if (! $schedule->rotation_days) {
            $result['missing'][] = 'Indicá cuántos días dura cada turno.';
        }
        if (! $schedule->rotation_starts_on) {
            $result['missing'][] = 'Indicá cuándo empieza la rotación.';
        }
        if ($schedule->days === []) {
            $result['missing'][] = 'Elegí al menos un día de guardia.';
        }
        if ($members->isNotEmpty() && $members->count() < (int) $schedule->technicians_needed) {
            $result['missing'][] = 'La guardia es de '.$schedule->technicians_needed.' técnicos a la vez y la rotación tiene '.$members->count().': agregá más participantes o bajá «¿Cuántos técnicos cubren a la vez?».';
        }
        if ($schedule->rotation_ends_on && $schedule->rotation_starts_on && $schedule->rotation_ends_on->lt($schedule->rotation_starts_on)) {
            $result['missing'][] = 'La fecha hasta la que se generan turnos es anterior al inicio de la rotación.';
        }
        if ($result['missing']) {
            return $result;
        }

        $now = now()->startOfMinute();
        $plan = app(RotationService::class)->plan($schedule, $members, $now);
        $name = fn (array $ids) => collect($ids)->map(fn ($id) => $members->firstWhere('user_id', $id)?->user?->name ?? $technicians[$id]->name ?? User::withoutGlobalScopes()->find($id)?->name ?? 'Técnico')->implode(' y ');

        $result['rows'] = collect($plan)->take(8)->map(fn ($p) => [
            'start' => $p['start'], 'end' => $p['end'], 'who' => $p['users'] === [] ? null : $name($p['users']), 'status' => $p['status'],
        ])->all();
        $result['changes'] = collect($plan)->where('status', 'change')->count();

        $anchor = Carbon::parse($schedule->rotation_starts_on->format('Y-m-d').' '.$schedule->starts_time);
        if ($plan === []) {
            $result['missing'][] = $schedule->rotation_ends_on && $schedule->rotation_ends_on->lt(today())
                ? 'La fecha hasta la que se generan turnos ya pasó: no se crearía ningún turno.'
                : 'Con estos datos no se genera ningún turno en las próximas semanas.';

            return $result;
        }
        if ($anchor->lt($now)) {
            $result['warnings'][] = 'La rotación empieza el '.$anchor->format('d/m').', que ya pasó: no se crean turnos hacia atrás. El primero que se genera es el que empieza '.OnCallText::short($plan[0]['start']).'.';
        }
        if ($uncovered = collect($plan)->where('status', 'uncovered')->count()) {
            $result['warnings'][] = $uncovered.' '.($uncovered === 1 ? 'turno queda' : 'turnos quedan').' sin técnico disponible (por las fechas en que no están disponibles). Vas a verlos en «Para revisar».';
        }
        if ($manual = collect($plan)->where('status', 'manual')->count()) {
            $result['warnings'][] = $manual.' '.($manual === 1 ? 'turno tiene' : 'turnos tienen').' un cambio hecho a mano (un reemplazo o una asignación): se respeta y no se recalcula.';
        }
        if ($result['changes']) {
            $result['warnings'][] = 'Al guardar, '.$result['changes'].' '.($result['changes'] === 1 ? 'turno ya generado cambia' : 'turnos ya generados cambian').' de técnico y se avisa a los afectados. Los turnos pasados y el que está en curso no cambian.';
        }

        $first = collect($plan)->take(min(4, max(2, $members->count() + 1)))
            ->map(fn ($p) => ($p['users'] === [] ? 'sin técnico' : $name($p['users'])).' '.OnCallText::short($p['start']).' a '.OnCallText::short($p['end']))->values();
        $result['example'] = 'Así se alternan: '.$first->implode('; después, ').($members->count() > 1 ? '; y vuelve a empezar.' : '.');

        return $result;
    }

    public static function infolist(Schema $schema): Schema
    {
        $service = fn () => app(OnCallService::class);

        // Arriba lo que se consulta siempre (ahora, después, lo que falta);
        // la configuración de la guardia, plegada.
        return $schema->components([
            Section::make('Cobertura')->columnSpanFull()->columns(['default' => 1, 'md' => 2])->schema([
                TextEntry::make('now')->label('De guardia ahora')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        if (! $r->covers(now())) {
                            return 'Ahora no es horario de guardia';
                        }
                        $names = $service()->shiftsAt($r, now())->map(fn (OnCallShift $s) => $s->user->name);

                        return $names->isEmpty() ? 'Sin técnico' : $names->implode(', ').' · hasta '.OnCallText::short($r->currentWindow(now())[1]);
                    })
                    ->color(fn ($state) => $state === 'Sin técnico' ? 'danger' : null)->weight('bold')->size('lg'),
                TextEntry::make('next')->label('Próxima guardia')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        $window = $r->is_active ? $r->nextWindow($r->currentWindow(now())[1] ?? now()) : null;
                        if (! $window) {
                            return '—';
                        }
                        $names = $service()->shiftsAt($r, $window[0])->map(fn (OnCallShift $s) => $s->user->name);

                        return ($names->isEmpty() ? 'Sin técnico' : $names->implode(', ')).' · '.OnCallText::span($window[0], $window[1]);
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'Sin técnico') ? 'danger' : null),
                TextEntry::make('gaps')->label('Turnos sin técnico (próximos 14 días)')->columnSpanFull()
                    ->state(function (OnCallSchedule $r) use ($service) {
                        $gaps = $service()->gaps($r, now(), now()->addDays(14));

                        return $gaps === [] ? 'Ninguno: todas las franjas tienen técnico.' : collect($gaps)->take(8)
                            ->map(fn ($g) => OnCallText::span($g['start'], $g['end']).($g['assigned'] ? " ({$g['assigned']} de {$g['needed']})" : ''))
                            ->implode(' · ').(count($gaps) > 8 ? ' · y '.(count($gaps) - 8).' más' : '');
                    })
                    ->color(fn ($state) => str_starts_with((string) $state, 'Ninguno') ? 'success' : 'danger'),
                TextEntry::make('notes')->label('Indicaciones para los técnicos')->columnSpanFull()->placeholder('—'),
            ]),
            Section::make('Horario y configuración')->columnSpanFull()->columns(['default' => 1, 'md' => 4])->collapsible()->collapsed()
                ->description('Cuándo hace falta alguien de guardia y cómo se asigna. Para cambiarlo: «Más» → «Cambiar horario o rotación».')
                ->schema([
                    TextEntry::make('coverage')->label('Horario')->state(fn (OnCallSchedule $r) => $r->coverageSentence())->columnSpan(['md' => 2]),
                    TextEntry::make('mode')->label('Cómo se asigna')
                        ->state(fn (OnCallSchedule $r) => $r->modeLabel().($r->isRotation() && $r->rotation_ends_on ? ', hasta el '.$r->rotation_ends_on->format('d/m/Y') : '')),
                    TextEntry::make('technicians_needed')->label('Técnicos a la vez'),
                    TextEntry::make('is_active')->label('Estado')->state(fn (OnCallSchedule $r) => $r->is_active ? 'En uso' : 'Ya no se usa')
                        ->badge()->color(fn ($state) => $state === 'En uso' ? 'success' : 'gray'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(OnCallService::class);

        return $table
            ->heading('Horarios de guardia')
            ->description('Cuándo hace falta alguien de guardia. Abrí uno para ver sus turnos, el historial de cambios y los trabajos, o para cambiarlo.')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Guardia')->searchable()->sortable()->weight('bold')
                    ->description(fn (OnCallSchedule $r) => $r->coverageSentence())->wrap(),
                TextColumn::make('mode')->label('Cómo se asigna')->state(fn (OnCallSchedule $r) => $r->modeLabel())->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('on_duty')->label('De guardia ahora')
                    ->state(function (OnCallSchedule $r) use ($service) {
                        if (! $r->covers(now())) {
                            return 'Fuera de horario';
                        }
                        $names = $service()->shiftsAt($r, now())->map(fn (OnCallShift $s) => $s->user->name);

                        return $names->isEmpty() ? 'Sin técnico' : $names->implode(', ');
                    })
                    ->color(fn ($state) => match ($state) {
                        'Sin técnico' => 'danger', 'Fuera de horario' => 'gray', default => null
                    })->wrap(),
                TextColumn::make('gaps_count')->label('Turnos sin técnico (14 días)')->alignCenter()
                    ->state(fn (OnCallSchedule $r) => count($service()->gaps($r, now(), now()->addDays(14))))
                    ->color(fn ($state) => $state ? 'danger' : 'success'),
                IconColumn::make('is_active')->label('En uso')->boolean()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('En uso')->default(true),
            ])
            ->recordUrl(fn (OnCallSchedule $r) => static::getUrl('view', ['record' => $r]))
            ->emptyStateHeading('Todavía no hay horarios de guardia')
            ->emptyStateDescription('Agregá uno (por ejemplo, «Guardia nocturna», de lunes a viernes de 18:00 a 08:00) y después asigná quién cubre cada turno desde «Guardias».');
    }

    public static function getRelations(): array
    {
        return [
            ShiftsRelationManager::class,
            ChangesRelationManager::class,
            WorkOrdersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => OnCallHome::route('/'),
            'schedules' => ListOnCallSchedules::route('/horarios'),
            'create' => CreateOnCallSchedule::route('/create'),
            'view' => ViewOnCallSchedule::route('/{record}'),
            'edit' => EditOnCallSchedule::route('/{record}/edit'),
        ];
    }
}
