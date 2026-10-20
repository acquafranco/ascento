<?php

namespace App\Filament\Resources\OnCall;

use App\Filament\Resources\OnCall\Pages\OnCallRotationFeedback;
use App\Models\OnCallMember;
use App\Models\OnCallSchedule;
use App\Services\OnCall\OnCallService;
use App\Services\OnCall\OnCallText;
use App\Services\OnCall\RotationService;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ausencias en una rotación (vacaciones, licencia): el técnico se saltea en
 * esas fechas y cubre el siguiente de la lista; el orden no cambia.
 *
 * Es lo mismo que «Ausente desde / hasta» del formulario de la guardia, a un
 * clic desde la portada. Antes de confirmar se ve quién cubre cada turno. Se
 * recalculan solo los turnos de la rotación que todavía no empezaron (el que
 * está en curso y los cambios hechos a mano no se tocan: para eso, «Reemplazar»).
 */
class OnCallAbsences
{
    /** Participantes de las rotaciones que esta persona puede gestionar. */
    public static function members(): Collection
    {
        $schedules = OnCallActions::manageableSchedules()->filter(fn (OnCallSchedule $s) => $s->isRotation());

        return OnCallMember::query()->whereIn('on_call_schedule_id', $schedules->pluck('id'))
            ->with(['user' => fn ($q) => $q->withoutGlobalScopes(), 'schedule'])->orderBy('on_call_schedule_id')->orderBy('position')->get()
            ->filter(fn (OnCallMember $m) => $m->user && ! $m->user->trashed())->values();
    }

    private static function memberFor(mixed $id): OnCallMember
    {
        $member = self::members()->firstWhere('id', (int) $id);

        if (! $member) {
            throw ValidationException::withMessages(['member_id' => 'Elegí un técnico de una rotación de tu empresa.']);
        }

        return $member;
    }

    private static function label(OnCallMember $member, bool $several): string
    {
        return $member->user->name.($several ? ' · '.$member->schedule->name : '');
    }

    public static function mark(): Action
    {
        return Action::make('markAbsence')->label('Cargar una ausencia')->icon('heroicon-o-calendar-days')->color('gray')
            ->modalHeading('Cargar una ausencia')
            ->modalDescription('Vacaciones, licencia o un día que no puede. En esas fechas se saltea y cubre el siguiente de la lista; el orden de la rotación no cambia.')
            ->modalSubmitActionLabel('Guardar ausencia')
            ->modalWidth('xl')
            ->visible(fn () => self::members()->isNotEmpty())
            ->fillForm(function (array $arguments) {
                $member = filled($arguments['member'] ?? null) ? self::members()->firstWhere('id', (int) $arguments['member']) : null;

                return ['member_id' => $member?->id, 'from' => today()->toDateString(), 'until' => null];
            })
            ->schema(fn () => [
                Select::make('member_id')->label('¿Quién no va a estar?')->required()->live()
                    ->in(fn () => self::members()->pluck('id')->all())
                    ->options(function () {
                        $members = self::members();
                        $several = $members->pluck('on_call_schedule_id')->unique()->count() > 1;

                        return $members->mapWithKeys(fn (OnCallMember $m) => [$m->id => self::label($m, $several)])->all();
                    }),
                DatePicker::make('from')->label('Desde')->required()->minDate(today())->live(),
                DatePicker::make('until')->label('Hasta (inclusive)')->required()->afterOrEqual('from')->live(),
                Text::make(fn (Get $get) => self::summary($get))->visible(fn (Get $get) => filled($get('member_id')) && filled($get('from')) && filled($get('until'))),
            ])
            ->action(function (array $data) {
                $member = self::memberFor($data['member_id']);
                abort_unless(OnCallScheduleResource::canEdit($member->schedule), 403);
                $from = Carbon::parse($data['from'])->startOfDay();
                $until = Carbon::parse($data['until'])->startOfDay();

                if ($from->lt(today()) || $until->lt($from)) {
                    throw ValidationException::withMessages(['until' => 'Elegí fechas desde hoy, con el fin igual o posterior al inicio.']);
                }

                $result = DB::transaction(function () use ($member, $from, $until) {
                    $schedule = app(OnCallService::class)->lockSchedule($member->schedule);
                    $member->forceFill(['excluded_from' => $from->toDateString(), 'excluded_until' => $until->toDateString()])->save();
                    app(OnCallService::class)->log($schedule, null, 'rotation_updated', $member->user_id, null, $from, $until->copy()->addDay(), null,
                        'Ausencia de '.$member->user->name.' del '.$from->format('d/m').' al '.$until->format('d/m').': se saltea en la rotación.', auth()->user());

                    return app(RotationService::class)->sync($schedule, auth()->user(), reason: 'Ausencia de '.$member->user->name);
                });

                OnCallRotationFeedback::send($result);
                Notification::make()->title('Ausencia guardada')
                    ->body($member->user->name.' no cubre guardias del '.$from->format('d/m').' al '.$until->format('d/m').'.'
                        .(self::onDutyDuring($member, $from, $until) ? ' Está de guardia ahora: si no puede terminar este turno, usá «Reemplazar».' : ''))
                    ->success()->send();
            });
    }

    public static function clear(): Action
    {
        return Action::make('clearAbsence')->label('Quitar ausencia')->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Quitar la ausencia')
            ->modalDescription('Vuelve a cubrir sus turnos de la rotación desde ahora. Los turnos ya empezados y los cambios hechos a mano no cambian.')
            ->modalSubmitActionLabel('Quitar ausencia')
            ->action(function (array $arguments) {
                $member = self::memberFor($arguments['member'] ?? null);
                abort_unless(OnCallScheduleResource::canEdit($member->schedule), 403);

                $result = DB::transaction(function () use ($member) {
                    $schedule = app(OnCallService::class)->lockSchedule($member->schedule);
                    $member->forceFill(['excluded_from' => null, 'excluded_until' => null])->save();
                    app(OnCallService::class)->log($schedule, null, 'rotation_updated', null, $member->user_id, now(), null, null,
                        'Se quitó la ausencia de '.$member->user->name.'.', auth()->user());

                    return app(RotationService::class)->sync($schedule, auth()->user(), reason: 'Ausencia quitada');
                });

                OnCallRotationFeedback::send($result);
            });
    }

    /** Quién cubre cada turno afectado, con la ausencia aplicada (sin guardar nada). */
    private static function summary(Get $get): string
    {
        return self::summaryFor((int) $get('member_id'), (string) $get('from'), (string) $get('until'));
    }

    public static function summaryFor(int $memberId, string $fromDate, string $untilDate): string
    {
        $member = self::members()->firstWhere('id', $memberId);
        if (! $member) {
            return '';
        }

        try {
            $from = Carbon::parse($fromDate)->startOfDay();
            $until = Carbon::parse($untilDate)->startOfDay();
        } catch (\Throwable) {
            return '';
        }
        if ($until->lt($from)) {
            return '';
        }

        $schedule = $member->schedule;
        $members = OnCallMember::withoutGlobalScopes()->where('on_call_schedule_id', $schedule->id)->orderBy('position')
            ->with(['user' => fn ($q) => $q->withoutGlobalScopes()])->get()
            ->map(fn (OnCallMember $m) => $m->id === $member->id ? (clone $m)->forceFill(['excluded_from' => $from, 'excluded_until' => $until]) : $m);
        $end = $until->copy()->addDay();

        $rows = collect(app(RotationService::class)->plan($schedule, $members, now()->startOfMinute(), $end->copy()->addDays(max(1, (int) $schedule->rotation_days))))
            ->filter(fn ($p) => $p['start']->lt($end) && $p['end']->gt($from))
            ->map(function ($p) use ($members) {
                $who = collect($p['users'])->map(fn ($id) => $members->firstWhere('user_id', $id)?->user?->name ?? 'Técnico')->implode(' y ');

                return OnCallText::short($p['start']).' a '.OnCallText::short($p['end']).': '
                    .($p['status'] === 'manual' ? $who.' (cambio hecho a mano, no se toca)' : ($who !== '' ? $who : 'sin técnico disponible'));
            });

        $text = $rows->isEmpty()
            ? 'En esas fechas '.$member->user->name.' no tenía turnos de la rotación por empezar.'
            : 'Así quedan los turnos: '.$rows->implode(' · ').'.';

        if (self::onDutyDuring($member, $from, $until)) {
            $text .= ' Ahora está de guardia: ese turno no cambia; si no puede terminarlo, usá «Reemplazar».';
        }
        if (($member->excluded_from || $member->excluded_until) && ($member->excluded_until === null || $member->excluded_until->gte(today()))) {
            $text .= ' Ya tenía una ausencia cargada'.($member->excluded_from ? ' del '.$member->excluded_from->format('d/m') : '').($member->excluded_until ? ' al '.$member->excluded_until->format('d/m') : '').': se reemplaza por esta.';
        }

        return $text;
    }

    private static function onDutyDuring(OnCallMember $member, Carbon $from, Carbon $until): bool
    {
        return $from->lte(today()) && $until->gte(today())
            && app(OnCallService::class)->shiftsAt($member->schedule, now())->contains('user_id', $member->user_id);
    }

    /**
     * Red de seguridad: si la tarea de cada noche dejó de correr, la rotación
     * se extiende al abrir «Guardias» (mismo cálculo, no toca lo que ya existe).
     */
    public static function topUp(int $companyId): void
    {
        $limit = now()->addDays(28);

        OnCallSchedule::query()->where('company_id', $companyId)->where('is_active', true)->where('mode', OnCallSchedule::ROTATION)->get()
            ->filter(fn (OnCallSchedule $s) => OnCallScheduleResource::canEdit($s))
            ->each(function (OnCallSchedule $schedule) use ($limit) {
                $last = $schedule->shifts()->active()->where('origin', 'rotation')->max('starts_at');
                $endsBefore = $schedule->rotation_ends_on && $schedule->rotation_ends_on->lt($limit);

                if (! $endsBefore && ($last === null || Carbon::parse($last)->lt($limit))) {
                    app(RotationService::class)->sync($schedule, null, reason: 'Turnos de las próximas semanas');
                }
            });
    }
}
