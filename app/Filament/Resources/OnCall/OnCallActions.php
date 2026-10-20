<?php

namespace App\Filament\Resources\OnCall;

use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Services\OnCall\OnCallService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;

/**
 * Acciones de guardias del panel (la lógica está en OnCallService). Cada una
 * exige poder gestionar guardias, también ante solicitudes armadas a mano.
 */
class OnCallActions
{
    /** Asignar un técnico a la guardia por un período (o de forma permanente). */
    public static function assign(): Action
    {
        return Action::make('assign')->label('Asignar técnico')->icon('heroicon-o-user-plus')
            ->visible(fn (OnCallSchedule $record) => $record->is_active)
            ->authorize(fn (OnCallSchedule $record) => OnCallScheduleResource::canEdit($record))
            ->modalHeading('Asignar técnico a la guardia')
            ->modalDescription(fn (OnCallSchedule $record) => $record->name.' · '.$record->coverageLabel().'. El técnico cubre las franjas de la guardia dentro del período.')
            ->schema([
                Select::make('user_id')->label('Técnico')->required()->searchable()
                    ->options(fn () => OnCallService::technicians()->pluck('name', 'id')),
                DateTimePicker::make('starts_at')->label('Desde')->required()->seconds(false)->native(false)->displayFormat('d/m/Y H:i')
                    ->default(fn (OnCallSchedule $record) => ($record->nextWindow(now())[0] ?? now())->format('Y-m-d H:i')),
                DateTimePicker::make('ends_at')->label('Hasta (vacío = permanente)')->seconds(false)->native(false)->displayFormat('d/m/Y H:i')->after('starts_at'),
                Textarea::make('reason')->label('Nota (opcional)')->rows(2)->maxLength(500),
            ])
            ->action(function (OnCallSchedule $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->assign(
                    $record, User::findOrFail($data['user_id']), Carbon::parse($data['starts_at']),
                    filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null, auth()->user(), $data['reason'] ?? null,
                ), 'Técnico asignado');
            });
    }

    /** Cambio de guardia: otro técnico cubre todo o parte del turno (desde ahora o más adelante). */
    public static function replace(): Action
    {
        return Action::make('replace')->label('Cambiar técnico')->icon('heroicon-o-arrows-right-left')->color('warning')
            ->visible(fn (OnCallShift $record) => $record->isActive() && ($record->ends_at === null || $record->ends_at->gt(now())))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule))
            ->modalHeading('Cambio de guardia')
            ->modalDescription(fn (OnCallShift $record) => 'Turno de '.$record->user?->name.' ('.$record->periodLabel().'). Lo que ya pasó no cambia; el resto del turno sigue a su nombre.')
            ->schema([
                Select::make('user_id')->label('Lo cubre')->required()->searchable()
                    ->options(fn (OnCallShift $record) => OnCallService::technicians()->reject(fn ($u) => $u->id === $record->user_id)->pluck('name', 'id')),
                DateTimePicker::make('from')->label('Desde')->required()->seconds(false)->native(false)->displayFormat('d/m/Y H:i')
                    ->default(fn (OnCallShift $record) => $record->starts_at->max(now())->format('Y-m-d H:i')),
                DateTimePicker::make('to')->label('Hasta (vacío = hasta el fin del turno)')->seconds(false)->native(false)->displayFormat('d/m/Y H:i')
                    ->default(fn (OnCallShift $record) => $record->ends_at?->format('Y-m-d H:i'))->after('from'),
                Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500)->placeholder('Ej: enfermedad, cambio acordado'),
            ])
            ->action(function (OnCallShift $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->replace(
                    $record, Carbon::parse($data['from']), filled($data['to'] ?? null) ? Carbon::parse($data['to']) : null,
                    User::findOrFail($data['user_id']), auth()->user(), $data['reason'] ?? null,
                ), 'Cambio de guardia registrado');
            });
    }

    /** Cancelar un turno que todavía no empezó. */
    public static function cancel(): Action
    {
        return Action::make('cancel')->label('Cancelar')->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (OnCallShift $record) => $record->isActive() && $record->starts_at->gt(now()))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule))
            ->modalHeading('Cancelar el turno')
            ->modalDescription('El turno queda cancelado en el historial y su período, sin técnico (aparece en «Huecos»).')
            ->schema([Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500)])
            ->action(function (OnCallShift $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->cancel($record, auth()->user(), $data['reason'] ?? null), 'Turno cancelado');
            });
    }

    /** Finalizar un turno en curso o permanente (desde ahora o una fecha futura). */
    public static function end(): Action
    {
        return Action::make('end')->label('Finalizar')->icon('heroicon-o-stop-circle')->color('gray')
            ->visible(fn (OnCallShift $record) => $record->isActive() && $record->starts_at->lte(now()) && ($record->ends_at === null || $record->ends_at->gt(now())))
            ->authorize(fn (OnCallShift $record) => OnCallScheduleResource::canEdit($record->schedule))
            ->modalHeading('Finalizar el turno')
            ->modalDescription('Lo ya cubierto queda en el historial; desde la fecha elegida, el técnico deja de estar de guardia.')
            ->schema([
                DateTimePicker::make('at')->label('Desde')->required()->seconds(false)->native(false)->displayFormat('d/m/Y H:i')->default(now()->format('Y-m-d H:i')),
                Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500),
            ])
            ->action(function (OnCallShift $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->endAt($record, Carbon::parse($data['at']), auth()->user(), $data['reason'] ?? null), 'Turno finalizado');
            });
    }

    /** Finalizar la guardia completa (cancela lo futuro, corta lo vigente; conserva el historial). */
    public static function finishSchedule(): Action
    {
        return Action::make('finishSchedule')->label('Finalizar guardia')->icon('heroicon-o-stop-circle')->color('danger')
            ->visible(fn (OnCallSchedule $record) => $record->is_active)
            ->authorize(fn (OnCallSchedule $record) => OnCallScheduleResource::canEdit($record))
            ->requiresConfirmation()
            ->modalHeading('Finalizar la guardia')
            ->modalDescription('Se cancelan los turnos futuros y los vigentes terminan ahora. El historial, los cambios y los trabajos vinculados se conservan.')
            ->schema([Textarea::make('reason')->label('Motivo (opcional)')->rows(2)->maxLength(500)])
            ->action(function (OnCallSchedule $record, array $data, Action $action) {
                self::run($action, fn () => app(OnCallService::class)->finishSchedule($record, auth()->user(), $data['reason'] ?? null), 'Guardia finalizada');
            });
    }

    /*
    |--------------------------------------------------------------------------
    | ACCIONES RÁPIDAS (portada de «Guardias», sin entrar a cada guardia)
    |--------------------------------------------------------------------------
    | Mismas reglas y mismo servicio que las de cada guardia: solo cambia que
    | la guardia (o el turno) se elige en el formulario. Se abren también desde
    | los botones de «Ahora» y «Después» con la guardia o el turno ya elegidos.
    */

    /** Guardias activas de la empresa que esta persona puede gestionar. */
    private static function manageableSchedules()
    {
        return OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->where('is_active', true)->orderBy('name')->get()
            ->filter(fn (OnCallSchedule $s) => OnCallScheduleResource::canEdit($s))->values();
    }

    private static function scheduleFor(mixed $id): OnCallSchedule
    {
        $schedule = OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->findOrFail((int) $id);
        abort_unless(OnCallScheduleResource::canEdit($schedule), 403);

        return $schedule;
    }

    /** Turnos que todavía se pueden cambiar: en curso o próximos (no cumplidos, ni modificados). */
    private static function changeableShifts()
    {
        $ids = self::manageableSchedules()->pluck('id');

        return OnCallShift::query()->whereIn('on_call_schedule_id', $ids)->active()
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->with(['schedule', 'user' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('starts_at')->limit(200)->get();
    }

    private static function shiftFor(mixed $id): OnCallShift
    {
        $shift = OnCallShift::query()->where('company_id', CompanyContext::currentId())->with('schedule')->findOrFail((int) $id);
        abort_unless($shift->schedule && OnCallScheduleResource::canEdit($shift->schedule), 403);

        return $shift;
    }

    /** «Asignar guardia»: elegir la guardia, el técnico y el período. */
    public static function assignAny(): Action
    {
        $start = fn ($scheduleId) => filled($scheduleId) && ($s = OnCallSchedule::query()->where('company_id', CompanyContext::currentId())->find((int) $scheduleId))
            ? ($s->currentWindow(now())[0] ?? $s->nextWindow(now())[0] ?? now())->max(now())->format('Y-m-d H:i')
            : now()->format('Y-m-d H:i');

        return Action::make('assignOnCall')->label('Asignar guardia')->icon('heroicon-o-user-plus')
            ->visible(fn () => self::manageableSchedules()->isNotEmpty())
            ->modalHeading('Asignar una guardia')
            ->modalDescription('Elegí la guardia y quién la cubre. Si no indicás un fin, la asignación queda hasta que la cambies.')
            ->modalSubmitActionLabel('Asignar')
            ->fillForm(function (array $arguments) use ($start) {
                $schedules = self::manageableSchedules();
                $id = $arguments['schedule'] ?? ($schedules->count() === 1 ? $schedules->first()->id : null);

                return ['schedule_id' => $id, 'starts_at' => $start($id)];
            })
            ->schema([
                Select::make('schedule_id')->label('Guardia')->required()->live()
                    ->options(fn () => self::manageableSchedules()->mapWithKeys(fn (OnCallSchedule $s) => [$s->id => $s->name.' · '.$s->coverageLabel()]))
                    ->afterStateUpdated(fn ($state, $set) => $set('starts_at', $start($state))),
                Select::make('user_id')->label('Técnico')->required()->searchable()
                    ->options(fn () => OnCallService::technicians()->pluck('name', 'id')),
                DateTimePicker::make('starts_at')->label('Desde')->required()->seconds(false)->native(false)->displayFormat('d/m/Y H:i'),
                DateTimePicker::make('ends_at')->label('Hasta (vacío = sin fecha de fin)')->seconds(false)->native(false)->displayFormat('d/m/Y H:i')->after('starts_at'),
                Textarea::make('reason')->label('Nota (opcional)')->rows(2)->maxLength(500),
            ])
            ->action(function (array $data, Action $action) {
                $schedule = self::scheduleFor($data['schedule_id'] ?? 0);
                self::run($action, fn () => app(OnCallService::class)->assign(
                    $schedule, User::findOrFail($data['user_id']), Carbon::parse($data['starts_at']),
                    filled($data['ends_at'] ?? null) ? Carbon::parse($data['ends_at']) : null, auth()->user(), $data['reason'] ?? null,
                ), 'Guardia asignada');
            });
    }

    /** «Reemplazar técnico»: elegir el turno (en curso o próximo) y quién lo cubre. */
    public static function replaceAny(): Action
    {
        $defaults = fn ($shiftId) => filled($shiftId) && ($shift = OnCallShift::query()->where('company_id', CompanyContext::currentId())->find((int) $shiftId))
            ? ['from' => $shift->starts_at->max(now())->format('Y-m-d H:i'), 'to' => $shift->ends_at?->format('Y-m-d H:i')]
            : ['from' => now()->format('Y-m-d H:i'), 'to' => null];

        return Action::make('replaceOnCall')->label('Reemplazar técnico')->icon('heroicon-o-arrows-right-left')->color('gray')
            ->visible(fn () => self::manageableSchedules()->isNotEmpty())
            ->modalHeading('Reemplazar a un técnico de guardia')
            ->modalDescription('Otro técnico cubre todo o parte de un turno. Lo que ya pasó no cambia y queda en el historial.')
            ->modalSubmitActionLabel('Guardar el cambio')
            ->fillForm(fn (array $arguments) => ['shift_id' => $arguments['shift'] ?? null] + $defaults($arguments['shift'] ?? null))
            ->schema([
                Select::make('shift_id')->label('Turno a reemplazar')->required()->searchable()->live()
                    ->options(fn () => self::changeableShifts()->mapWithKeys(fn (OnCallShift $s) => [$s->id => $s->label()]))
                    ->afterStateUpdated(function ($state, $set) use ($defaults) {
                        foreach ($defaults($state) as $field => $value) {
                            $set($field, $value);
                        }
                    }),
                Select::make('user_id')->label('Lo cubre')->required()->searchable()
                    ->options(function (Get $get) {
                        $current = OnCallShift::query()->find((int) $get('shift_id'))?->user_id;

                        return OnCallService::technicians()->reject(fn ($u) => $u->id === $current)->pluck('name', 'id');
                    }),
                DateTimePicker::make('from')->label('Desde')->required()->seconds(false)->native(false)->displayFormat('d/m/Y H:i'),
                DateTimePicker::make('to')->label('Hasta (vacío = hasta el fin del turno)')->seconds(false)->native(false)->displayFormat('d/m/Y H:i')->after('from'),
                Textarea::make('reason')->label('Motivo')->rows(2)->maxLength(500)->placeholder('Ej: enfermedad, cambio acordado'),
            ])
            ->action(function (array $data, Action $action) {
                $shift = self::shiftFor($data['shift_id'] ?? 0);
                self::run($action, fn () => app(OnCallService::class)->replace(
                    $shift, Carbon::parse($data['from']), filled($data['to'] ?? null) ? Carbon::parse($data['to']) : null,
                    User::findOrFail($data['user_id']), auth()->user(), $data['reason'] ?? null,
                ), 'Cambio de guardia registrado');
            });
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
