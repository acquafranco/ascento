<?php

namespace App\Filament\Resources\OnCall;

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallPlanner;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\OnCallText;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Exceptions\Halt;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Acciones de guardias del panel (la lógica está en OnCallService).
 *
 * «Asignar guardia» y «Reemplazar técnico» son asistentes de dos pasos: las
 * preguntas en palabras simples y, antes de confirmar, un resumen de lo que
 * va a pasar (OnCallPlanner). Si algo no se puede guardar, el primer paso no
 * avanza y explica por qué. Al confirmar, el servicio vuelve a validar todo.
 *
 * Cada acción exige poder gestionar guardias, también ante solicitudes
 * armadas a mano, y solo opera sobre guardias y turnos de la empresa actual.
 */
class OnCallActions
{
    /*
    |--------------------------------------------------------------------------
    | ASIGNAR GUARDIA
    |--------------------------------------------------------------------------
    */

    /** «Asignar guardia» de la portada: se elige la guardia si hay más de una. */
    public static function assignAny(): Action
    {
        return self::assignAction('assignOnCall', null)
            ->visible(fn () => self::manageableSchedules()->isNotEmpty());
    }

    /** «Asignar guardia» dentro de una guardia (o de su pestaña «Turnos»). */
    public static function assign(): Action
    {
        return self::assignAction('assign', fn (OnCallSchedule $record) => $record)
            ->visible(fn (OnCallSchedule $record) => $record->is_active)
            ->authorize(fn (OnCallSchedule $record) => OnCallScheduleResource::canEdit($record));
    }

    /** @param  ?Closure  $fixed  guardia ya elegida (recibe el registro) */
    private static function assignAction(string $name, ?Closure $fixed): Action
    {
        $scheduleOf = fn (Get $get) => self::findSchedule($get('schedule_id'));

        return Action::make($name)->label('Asignar guardia')->icon('heroicon-o-user-plus')
            ->modalHeading('Asignar una guardia')
            ->modalDescription('Elegí quién cubre y en qué período. Antes de guardar vas a ver un resumen.')
            ->modalSubmitActionLabel('Confirmar asignación')
            ->modalWidth('2xl')
            ->fillForm(function (array $arguments, ?OnCallSchedule $record = null) use ($fixed) {
                $schedules = self::manageableSchedules();
                $schedule = $fixed && $record ? $record : self::findSchedule($arguments['schedule'] ?? ($schedules->count() === 1 ? $schedules->first()->id : null));
                $start = filled($arguments['start'] ?? null) ? Carbon::parse($arguments['start']) : null;
                $end = filled($arguments['end'] ?? null) ? Carbon::parse($arguments['end']) : null;

                return ['schedule_id' => $schedule?->id, 'open_ended' => false, 'others' => []]
                    + self::periodFields($schedule, $start, $end);
            })
            ->steps([
                Step::make('Datos')->description('Quién y cuándo')
                    ->schema([
                        Select::make('schedule_id')->label('¿Qué guardia?')->required()->live()
                            ->options(fn () => self::manageableSchedules()->mapWithKeys(fn (OnCallSchedule $s) => [$s->id => $s->name.' — '.rtrim($s->coverageSentence(), '.')]))
                            ->visible(fn (?OnCallSchedule $record = null) => ! ($fixed && $record) && self::manageableSchedules()->count() > 1)
                            // Con una sola guardia no se pregunta, pero se envía igual.
                            ->dehydratedWhenHidden()
                            ->afterStateUpdated(function ($state, Set $set) {
                                foreach (self::periodFields(self::findSchedule($state)) as $field => $value) {
                                    $set($field, $value);
                                }
                            }),
                        Text::make(fn (Get $get) => ($s = $scheduleOf($get)) ? 'Horario de «'.$s->name.'»: '.$s->coverageSentence() : '')
                            ->color('gray')->visible(fn (Get $get) => $scheduleOf($get) !== null),
                        Select::make('user_id')->label('¿A quién asignamos?')->required()->searchable()->live()
                            ->options(fn () => OnCallService::technicians()->pluck('name', 'id'))
                            ->helperText('Solo aparecen técnicos activos de tu empresa.'),
                        Grid::make(['default' => 1, 'sm' => 2])->schema([
                            DatePicker::make('start_date')->label('¿Qué día comienza?')->required()->live(onBlur: true),
                            TimePicker::make('start_time')->label('¿A qué hora comienza?')->required()->seconds(false)->live(onBlur: true),
                        ]),
                        Toggle::make('open_ended')->label('Sin fecha de fin (queda asignado hasta que lo cambies)')->live(),
                        Grid::make(['default' => 1, 'sm' => 2])->visible(fn (Get $get) => ! $get('open_ended'))->schema([
                            DatePicker::make('end_date')->label('¿Hasta qué día cubre?')->required(fn (Get $get) => ! $get('open_ended'))->live(onBlur: true)
                                ->helperText('Si la guardia es de noche (por ejemplo, de 18:00 a 08:00), es el día siguiente.'),
                            TimePicker::make('end_time')->label('¿Hasta qué hora?')->required(fn (Get $get) => ! $get('open_ended'))->seconds(false)->live(onBlur: true),
                        ]),
                        Select::make('others')->label('¿Hay otros técnicos que también cubren ese turno?')->multiple()->searchable()
                            ->options(fn (Get $get) => OnCallService::technicians()->reject(fn ($u) => (int) $u->id === (int) $get('user_id'))->pluck('name', 'id'))
                            ->maxItems(fn (Get $get) => max(1, (int) ($scheduleOf($get)?->technicians_needed ?? 1) - 1))
                            ->visible(fn (Get $get) => (int) ($scheduleOf($get)?->technicians_needed ?? 1) > 1)
                            ->helperText(fn (Get $get) => 'Esta guardia es de '.($scheduleOf($get)?->technicians_needed ?? 1).' técnicos a la vez. Dejalo vacío si cubre solo.'),
                        Text::make('Esta guardia es de un técnico a la vez. Si necesitás que cubran dos juntos, cambiá «¿Cuántos técnicos cubren a la vez?» en su horario (Configuración avanzada).')
                            ->color('gray')
                            ->visible(fn (Get $get) => $scheduleOf($get) && (int) $scheduleOf($get)->technicians_needed === 1),
                        Textarea::make('reason')->label('Nota (opcional)')->rows(2)->maxLength(500)->placeholder('Ej: cubre el feriado'),
                    ])
                    ->afterValidation(function (Get $get) use ($scheduleOf) {
                        $schedule = $scheduleOf($get);
                        self::haltOnErrors($schedule ? self::assignmentPlan($schedule, $get) : ['errors' => ['Elegí la guardia.']]);
                    }),
                Step::make('Revisar y confirmar')->description('Qué va a pasar')
                    ->schema([
                        View::make('filament.on-call.summary')->viewData(fn (Get $get) => [
                            'plan' => ($schedule = $scheduleOf($get)) ? self::assignmentPlan($schedule, $get) : ['errors' => ['Elegí la guardia.'], 'warnings' => [], 'headline' => null, 'details' => []],
                        ]),
                    ]),
            ])
            ->action(function (array $data, Action $action, ?OnCallSchedule $record = null) use ($fixed) {
                $schedule = $fixed && $record ? $record : self::scheduleFor($data['schedule_id'] ?? 0);
                abort_unless(OnCallScheduleResource::canEdit($schedule), 403);
                $users = self::companyUsers([(int) ($data['user_id'] ?? 0), ...array_map('intval', (array) ($data['others'] ?? []))]);
                [$from, $to] = [self::moment($data['start_date'] ?? null, $data['start_time'] ?? null), ! empty($data['open_ended']) ? null : self::moment($data['end_date'] ?? null, $data['end_time'] ?? null)];

                self::run($action, function () use ($schedule, $users, $from, $to, $data) {
                    if (! $from || (empty($data['open_ended']) && ! $to)) {
                        throw ValidationException::withMessages(['starts_at' => 'Indicá cuándo empieza y cuándo termina.']);
                    }
                    app(OnCallService::class)->assignMany($schedule, $users, $from, $to, auth()->user(), $data['reason'] ?? null);
                }, 'Guardia asignada');
            });
    }

    /** Revisión previa con los datos del formulario. */
    private static function assignmentPlan(OnCallSchedule $schedule, Get $get): array
    {
        $open = (bool) $get('open_ended');

        return app(OnCallPlanner::class)->assignment(
            $schedule,
            [(int) $get('user_id'), ...array_map('intval', (array) ($get('others') ?? []))],
            self::moment($get('start_date'), $get('start_time')),
            $open ? null : self::moment($get('end_date'), $get('end_time')),
            $open,
        );
    }

    /**
     * Período sugerido: el indicado, o la primera franja sin técnico de los
     * próximos 14 días, o la próxima franja de la guardia. Solo propone: el
     * resumen muestra exactamente qué se va a guardar.
     */
    private static function periodFields(?OnCallSchedule $schedule, ?Carbon $start = null, ?Carbon $end = null): array
    {
        if (! $start && $schedule) {
            $gap = app(OnCallService::class)->gaps($schedule, now(), now()->addDays(14))[0] ?? null;
            [$start, $end] = $gap ? [$gap['start']->copy()->max(now()->startOfMinute()), $gap['end']] : ($schedule->nextWindow(now()) ?? [null, null]);
        }
        $start ??= now()->startOfMinute()->addMinutes(5 - now()->minute % 5);
        $end ??= $schedule ? ($schedule->windowStartingOn($start)[1] ?? null) : null;
        if ($end && $end->lte($start)) {
            $end = null;
        }

        return [
            'start_date' => $start->format('Y-m-d'), 'start_time' => $start->format('H:i'),
            'end_date' => $end?->format('Y-m-d'), 'end_time' => $end?->format('H:i'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | REEMPLAZAR TÉCNICO
    |--------------------------------------------------------------------------
    */

    /** «Reemplazar técnico» de la portada: se elige el turno (o viene elegido desde una franja). */
    public static function replaceAny(): Action
    {
        return self::replaceAction('replaceOnCall', false)
            ->visible(fn () => self::manageableSchedules()->isNotEmpty());
    }

    /** «Reemplazar» sobre un turno de la pestaña «Turnos» de una guardia. */
    public static function replace(): Action
    {
        return self::replaceAction('replace', true)
            ->visible(fn (OnCallShift $record) => $record->isActive() && ($record->ends_at === null || $record->ends_at->gt(now())))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule));
    }

    private static function replaceAction(string $name, bool $onRecord): Action
    {
        $shiftOf = fn (Get $get) => self::findShift($get('shift_id'));

        return Action::make($name)->label($onRecord ? 'Reemplazar' : 'Reemplazar técnico')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->modalHeading('Reemplazar a un técnico de guardia')
            ->modalDescription('Elegí el turno, quién lo reemplaza y por cuánto tiempo. Los demás turnos no cambian.')
            ->modalSubmitActionLabel('Confirmar reemplazo')
            ->modalWidth('2xl')
            ->fillForm(function (array $arguments, ?OnCallShift $record = null) use ($onRecord) {
                $shift = $onRecord && $record ? $record : self::findShift($arguments['shift'] ?? null);
                $segment = filled($arguments['from'] ?? null) && filled($arguments['to'] ?? null)
                    ? [Carbon::parse($arguments['from'])->max(now()->startOfMinute()), Carbon::parse($arguments['to'])]
                    : ($shift ? self::nextSegment($shift) : null);

                return ['shift_id' => $shift?->id] + self::replaceDefaults($shift, $segment);
            })
            ->steps([
                Step::make('Datos')->description('Turno, reemplazo y período')
                    ->schema([
                        Select::make('shift_id')->label('¿Qué turno hay que cambiar?')->required()->searchable()->live()
                            ->options(fn () => self::changeableShifts()->mapWithKeys(fn (OnCallShift $s) => [$s->id => self::shiftOption($s)]))
                            ->visible(fn (?OnCallShift $record = null) => ! ($onRecord && $record))
                            ->afterStateUpdated(function ($state, Set $set) {
                                $shift = self::findShift($state);
                                foreach (self::replaceDefaults($shift, $shift ? self::nextSegment($shift) : null) as $field => $value) {
                                    $set($field, $value);
                                }
                            }),
                        Text::make(fn (Get $get) => ($s = $shiftOf($get)) ? 'Lo tiene asignado '.$s->user?->name.' · '.$s->schedule?->name.' · '.OnCallPlanner::shiftPeriod($s).'.' : '')
                            ->weight('bold')->visible(fn (Get $get) => $shiftOf($get) !== null),
                        Select::make('user_id')->label('¿Quién lo reemplaza?')->required()->searchable()
                            ->options(fn (Get $get) => OnCallService::technicians()->reject(fn ($u) => (int) $u->id === (int) $shiftOf($get)?->user_id)->pluck('name', 'id')),
                        Hidden::make('segment_from'),
                        Hidden::make('segment_to'),
                        Radio::make('scope')->label('¿Por cuánto tiempo?')->required()->live()
                            ->options(fn (Get $get) => self::scopeOptions($shiftOf($get), $get('segment_from'), $get('segment_to'))),
                        Grid::make(['default' => 1, 'sm' => 2])->visible(fn (Get $get) => $get('scope') === 'custom')->schema([
                            DatePicker::make('from_date')->label('¿Desde qué día?')->required(fn (Get $get) => $get('scope') === 'custom')->live(onBlur: true),
                            TimePicker::make('from_time')->label('¿Desde qué hora?')->required(fn (Get $get) => $get('scope') === 'custom')->seconds(false)->live(onBlur: true),
                            DatePicker::make('to_date')->label('¿Hasta qué día?')->required(fn (Get $get) => $get('scope') === 'custom')->live(onBlur: true),
                            TimePicker::make('to_time')->label('¿Hasta qué hora?')->required(fn (Get $get) => $get('scope') === 'custom')->seconds(false)->live(onBlur: true),
                        ]),
                        Textarea::make('reason')->label('Motivo (opcional)')->rows(2)->maxLength(500)->placeholder('Ej: enfermedad, cambio acordado'),
                    ])
                    ->afterValidation(function (Get $get) use ($shiftOf) {
                        $shift = $shiftOf($get);
                        self::haltOnErrors($shift ? self::replacementPlan($shift, $get) : ['errors' => ['Elegí el turno que hay que cambiar.']]);
                    }),
                Step::make('Revisar y confirmar')->description('Qué va a pasar')
                    ->schema([
                        View::make('filament.on-call.summary')->viewData(fn (Get $get) => [
                            'plan' => ($shift = $shiftOf($get)) ? self::replacementPlan($shift, $get) : ['errors' => ['Elegí el turno que hay que cambiar.'], 'warnings' => [], 'headline' => null, 'details' => []],
                        ]),
                    ]),
            ])
            ->action(function (array $data, Action $action, ?OnCallShift $record = null) use ($onRecord) {
                $shift = $onRecord && $record ? $record : self::shiftFor($data['shift_id'] ?? 0);
                abort_unless($shift->schedule && OnCallScheduleResource::canEdit($shift->schedule), 403);
                [$from, $to] = self::replacementPeriod($shift, $data);
                $user = self::companyUsers([(int) ($data['user_id'] ?? 0)])[0];

                self::run($action, function () use ($shift, $from, $to, $user, $data) {
                    if (! $from) {
                        throw ValidationException::withMessages(['from' => 'Indicá desde cuándo se aplica el reemplazo.']);
                    }
                    app(OnCallService::class)->replace($shift, $from, $to, $user, auth()->user(), $data['reason'] ?? null);
                }, 'Reemplazo registrado');
            });
    }

    private static function replacementPlan(OnCallShift $shift, Get $get): array
    {
        [$from, $to] = self::replacementPeriod($shift, [
            'scope' => $get('scope'), 'segment_from' => $get('segment_from'), 'segment_to' => $get('segment_to'),
            'from_date' => $get('from_date'), 'from_time' => $get('from_time'), 'to_date' => $get('to_date'), 'to_time' => $get('to_time'),
        ]);
        $user = filled($get('user_id')) ? User::withoutGlobalScopes()->where('company_id', CompanyContext::currentId())->find((int) $get('user_id')) : null;

        return app(OnCallPlanner::class)->replacement($shift, $user, $from, $to);
    }

    /** @return array{0: ?Carbon, 1: ?Carbon} desde y hasta (null = hasta el fin del turno) */
    private static function replacementPeriod(OnCallShift $shift, array $data): array
    {
        // Una franja en curso se reemplaza desde el momento en que se confirma
        // (el modal pudo quedar abierto unos minutos); el resumen lo muestra.
        $now = now()->startOfMinute();

        return match ($data['scope'] ?? null) {
            'segment' => [self::parse($data['segment_from'] ?? null)?->max($now), self::parse($data['segment_to'] ?? null)],
            'rest' => [$shift->starts_at->copy()->max($now), null],
            'custom' => [self::moment($data['from_date'] ?? null, $data['from_time'] ?? null), self::moment($data['to_date'] ?? null, $data['to_time'] ?? null)],
            default => [null, null],
        };
    }

    /** La franja en curso o la próxima del turno (desde ahora), recortada al turno. */
    private static function nextSegment(OnCallShift $shift): ?array
    {
        $schedule = $shift->schedule;
        $from = $shift->starts_at->copy()->max(now()->startOfMinute());
        if (! $schedule || ($shift->ends_at && $from->gte($shift->ends_at))) {
            return null;
        }
        $window = $schedule->currentWindow($from) ?? $schedule->nextWindow($from);
        if (! $window || ($shift->ends_at && $window[0]->gte($shift->ends_at))) {
            return null;
        }

        return [$window[0]->copy()->max($from), $shift->ends_at ? $window[1]->copy()->min($shift->ends_at) : $window[1]];
    }

    private static function replaceDefaults(?OnCallShift $shift, ?array $segment): array
    {
        $from = $segment[0] ?? $shift?->starts_at->copy()->max(now()->startOfMinute()) ?? now()->startOfMinute();
        $to = $segment[1] ?? $shift?->ends_at;

        return [
            'scope' => $segment ? 'segment' : 'rest',
            'segment_from' => $segment ? $segment[0]->format('Y-m-d H:i') : null,
            'segment_to' => $segment ? $segment[1]->format('Y-m-d H:i') : null,
            'from_date' => $from->format('Y-m-d'), 'from_time' => $from->format('H:i'),
            'to_date' => $to?->format('Y-m-d'), 'to_time' => $to?->format('H:i'),
        ];
    }

    /** Opciones de «¿Por cuánto tiempo?» con las fechas concretas. */
    private static function scopeOptions(?OnCallShift $shift, ?string $segmentFrom, ?string $segmentTo): array
    {
        if (! $shift) {
            return [];
        }
        $name = $shift->user?->name ?? 'el técnico';
        $start = $shift->starts_at->copy()->max(now()->startOfMinute());
        $options = [];

        $to = null;
        if (($from = self::parse($segmentFrom)?->max($start)) && ($to = self::parse($segmentTo))) {
            $options['segment'] = 'Solo la franja de '.OnCallText::span($from, $to);
        }
        // Si el turno es justo esa franja, «todo lo que queda» sería la misma opción.
        $sameAsSegment = isset($options['segment'], $to) && $shift->ends_at && $to->eq($shift->ends_at);
        if (! $sameAsSegment) {
            $options['rest'] = $shift->ends_at
            ? 'Todo lo que queda del turno de '.$name.': '.OnCallText::short($start).' a '.OnCallText::short($shift->ends_at)
                : 'De forma permanente, en lugar de '.$name.' (desde '.OnCallText::short($start).')';
        }
        $options['custom'] = 'Elijo las fechas y horas';

        return $options;
    }

    private static function shiftOption(OnCallShift $shift): string
    {
        $state = $shift->includes(now()) ? 'en curso' : 'próximo';

        return ($shift->user?->name ?? 'Técnico').' — '.($shift->schedule?->name ?? 'Guardia').' — '.OnCallPlanner::shiftPeriod($shift).' ('.$state.')';
    }

    /*
    |--------------------------------------------------------------------------
    | CANCELAR, FINALIZAR (desde la pestaña «Turnos» de una guardia)
    |--------------------------------------------------------------------------
    */

    /** Cancelar un turno que todavía no empezó. */
    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Cancelar turno')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (OnCallShift $record) => $record->isActive() && $record->starts_at->gt(now()))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule))
            ->modalHeading('Cancelar el turno')
            ->modalDescription(fn (OnCallShift $record) => 'El turno de '.$record->user?->name.' ('.OnCallPlanner::shiftPeriod($record).') queda cancelado en el historial y ese período, sin técnico. Le avisamos al técnico.')
            ->modalSubmitActionLabel('Sí, cancelar el turno')
            ->schema([Textarea::make('reason')->label('Motivo (opcional)')->rows(2)->maxLength(500)])
            ->action(function (OnCallShift $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->cancel($record, auth()->user(), $data['reason'] ?? null), 'Turno cancelado');
            });
    }

    /** Terminar antes un turno en curso o sin fecha de fin (desde ahora o una fecha futura). */
    public static function end(): Action
    {
        return Action::make('end')->label('Terminar turno')->icon('heroicon-o-stop-circle')->color('gray')
            ->visible(fn (OnCallShift $record) => $record->isActive() && $record->starts_at->lte(now()) && ($record->ends_at === null || $record->ends_at->gt(now())))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule))
            ->modalHeading('Terminar el turno antes')
            ->modalDescription(fn (OnCallShift $record) => 'Lo ya cubierto por '.$record->user?->name.' queda en el historial. Desde la fecha elegida deja de estar de guardia y ese período queda sin técnico.')
            ->modalSubmitActionLabel('Terminar el turno')
            ->schema([
                DateTimePicker::make('at')->label('¿Desde cuándo deja de estar de guardia?')->required()->seconds(false)->default(now()->format('Y-m-d H:i')),
                Textarea::make('reason')->label('Motivo (opcional)')->rows(2)->maxLength(500),
            ])
            ->action(function (OnCallShift $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->endAt($record, Carbon::parse($data['at']), auth()->user(), $data['reason'] ?? null), 'Turno terminado');
            });
    }

    /** Finalizar la guardia completa (cancela lo futuro, corta lo vigente; conserva el historial). */
    public static function finishSchedule(): Action
    {
        return Action::make('finishSchedule')->label('Dejar de usar esta guardia')->icon('heroicon-o-stop-circle')->color('danger')
            ->visible(fn (OnCallSchedule $record) => $record->is_active)
            ->authorize(fn (OnCallSchedule $record) => OnCallScheduleResource::canEdit($record))
            ->requiresConfirmation()
            ->modalHeading('Dejar de usar esta guardia')
            ->modalDescription(fn (OnCallSchedule $record) => 'Se cancelan los turnos futuros de «'.$record->name.'» y los que están en curso terminan ahora (se avisa a los técnicos). El historial, los cambios y los trabajos vinculados se conservan.')
            ->modalSubmitActionLabel('Sí, dejar de usarla')
            ->schema([Textarea::make('reason')->label('Motivo (opcional)')->rows(2)->maxLength(500)])
            ->action(function (OnCallSchedule $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->finishSchedule($record, auth()->user(), $data['reason'] ?? null), 'Guardia finalizada');
            });
    }

    /*
    |--------------------------------------------------------------------------
    | INTERNOS
    |--------------------------------------------------------------------------
    */

    /** Guardias activas de la empresa que esta persona puede gestionar. */
    public static function manageableSchedules(): Collection
    {
        return OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (OnCallSchedule $s) => OnCallScheduleResource::canEdit($s))->values();
    }

    private static function findSchedule(mixed $id): ?OnCallSchedule
    {
        return filled($id) ? self::manageableSchedules()->firstWhere('id', (int) $id) : null;
    }

    private static function scheduleFor(mixed $id): OnCallSchedule
    {
        $schedule = OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->find((int) $id);
        if (! $schedule) {
            throw ValidationException::withMessages(['schedule_id' => 'Elegí una guardia de tu empresa.']);
        }
        abort_unless(OnCallScheduleResource::canEdit($schedule), 403);

        return $schedule;
    }

    /** Turnos que todavía se pueden cambiar: en curso o próximos (no cumplidos, ni modificados). */
    private static function changeableShifts(): Collection
    {
        $ids = self::manageableSchedules()->pluck('id');

        return OnCallShift::query()->whereIn('on_call_schedule_id', $ids)->active()
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with(['schedule', 'user' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('starts_at')->limit(200)->get();
    }

    private static function findShift(mixed $id): ?OnCallShift
    {
        if (blank($id)) {
            return null;
        }
        $shift = OnCallShift::query()->where('company_id', CompanyContext::currentId())->with(['schedule', 'user' => fn ($q) => $q->withoutGlobalScopes()])->find((int) $id);

        return $shift && $shift->schedule && OnCallScheduleResource::canEdit($shift->schedule) ? $shift : null;
    }

    private static function shiftFor(mixed $id): OnCallShift
    {
        $shift = OnCallShift::query()->where('company_id', CompanyContext::currentId())->with('schedule')->findOrFail((int) $id);
        abort_unless($shift->schedule && OnCallScheduleResource::canEdit($shift->schedule), 403);

        return $shift;
    }

    /**
     * Usuarios elegidos, solo de la empresa actual (el servicio valida además que sean técnicos activos).
     *
     * @return list<User>
     */
    private static function companyUsers(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $users = User::query()->where('company_id', CompanyContext::currentId())->whereIn('id', $ids ?: [0])->get()->keyBy('id');
        if ($ids === [] || $users->count() !== count($ids)) {
            throw ValidationException::withMessages(['user_id' => 'Elegí técnicos activos de tu empresa.']);
        }

        return array_map(fn ($id) => $users[$id], $ids);
    }

    /** Fecha (Y-m-d) y hora (H:i) del formulario → momento, o null si falta algo. */
    private static function moment(mixed $date, mixed $time): ?Carbon
    {
        if (blank($date) || blank($time)) {
            return null;
        }
        try {
            return Carbon::parse(substr((string) $date, 0, 10).' '.substr((string) $time, 0, 5))->startOfMinute();
        } catch (Throwable) {
            return null;
        }
    }

    private static function parse(mixed $value): ?Carbon
    {
        try {
            return filled($value) ? Carbon::parse($value)->startOfMinute() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** Si la revisión previa encontró errores, el paso no avanza y los explica. */
    private static function haltOnErrors(array $plan): void
    {
        if (! empty($plan['errors'])) {
            Notification::make()->title('Revisá estos datos')->body(implode("\n", $plan['errors']))->danger()->persistent()->send();

            throw new Halt;
        }
    }

    /** Ejecuta y muestra el resultado; un rechazo del servidor deja el formulario abierto con el motivo. */
    private static function run(Action $action, callable $callback, string $success): void
    {
        try {
            $callback();
        } catch (ValidationException $e) {
            Notification::make()->title('No se guardó el cambio')->body(collect($e->errors())->flatten()->first())->danger()->send();
            $action->halt();
        }

        Notification::make()->title($success)->success()->send();
    }
}
