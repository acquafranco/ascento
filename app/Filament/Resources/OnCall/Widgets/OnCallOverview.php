<?php

namespace App\Filament\Resources\OnCall\Widgets;

use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Models\OnCallChange;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Services\OnCall\OnCallService;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Filament\Widgets\Widget;
use Throwable;

/**
 * Lo primero que se ve en «Guardias», en orden de uso:
 *
 * 1. Ahora: quién está de guardia (o «sin cobertura»), con «Reemplazar».
 * 2. Después: la próxima franja de cada guardia y quién la cubre.
 * 3. Franjas sin técnico en los próximos 7 días (si hay), con «Asignar».
 * 4. Plegados: el calendario de 14 días, «¿quién está de guardia el…?» y
 *    los últimos cambios.
 *
 * Solo muestra: las acciones abren los formularios de la página (asignar y
 * reemplazar, OnCallActions), que validan todo en OnCallService. Todo de la
 * empresa actual.
 */
class OnCallOverview extends Widget
{
    protected string $view = 'filament.on-call.overview';

    protected int|string|array $columnSpan = 'full';

    /** Consulta «¿quién estará / estuvo de guardia?» (Y-m-d\TH:i). */
    public ?string $at = null;

    public static function canView(): bool
    {
        return OnCallScheduleResource::canViewAny();
    }

    protected function getViewData(): array
    {
        $companyId = (int) CompanyContext::currentId();
        $service = app(OnCallService::class);
        $names = fn ($shifts) => collect($shifts)->map(fn (OnCallShift $s) => $s->user->name)->implode(', ');
        $now = now();

        $at = null;
        if (filled($this->at)) {
            try {
                $at = Carbon::parse($this->at);
            } catch (Throwable) {
                $at = null;
            }
        }

        $schedules = OnCallSchedule::where('company_id', $companyId)->where('is_active', true)->orderBy('name')->get();

        // Después: la franja siguiente de cada guardia (la que empieza cuando termina la actual).
        $next = $schedules->map(function (OnCallSchedule $schedule) use ($service, $now) {
            $window = $schedule->nextWindow($schedule->currentWindow($now)[1] ?? $now);

            return $window ? ['schedule' => $schedule, 'window' => $window, 'shifts' => $service->shiftsAt($schedule, $window[0]), 'needed' => (int) $schedule->technicians_needed] : null;
        })->filter()->sortBy(fn ($row) => $row['window'][0]->timestamp)->values();

        // Franjas sin técnico (o con menos de los necesarios) en los próximos 7 días.
        $gaps = $schedules->map(fn (OnCallSchedule $schedule) => ['schedule' => $schedule, 'gaps' => $service->gaps($schedule, $now, $now->copy()->addDays(7))])
            ->filter(fn ($row) => $row['gaps'] !== [])->values();

        // Calendario: franjas de los próximos 14 días, agrupadas por día.
        $calendar = $schedules->flatMap(fn (OnCallSchedule $schedule) => collect($schedule->windowsBetween($now, $now->copy()->addDays(14)))
            ->filter(fn ($w) => $w[1]->gt($now))
            ->map(fn ($w) => ['schedule' => $schedule, 'start' => $w[0], 'end' => $w[1],
                'names' => $names($service->shiftsAt($schedule, $w[0]->max($now))), 'needed' => (int) $schedule->technicians_needed]))
            ->sortBy(fn ($row) => $row['start']->timestamp)->take(60)
            ->groupBy(fn ($row) => $row['start']->toDateString());

        return [
            'now' => $service->onDutyAt($companyId, $now),
            'next' => $next,
            'gaps' => $gaps,
            'calendar' => $calendar,
            'query' => $at ? $service->onDutyAt($companyId, $at) : null,
            'queryAt' => $at,
            'changes' => OnCallChange::where('company_id', $companyId)->with(['schedule', 'fromUser', 'toUser', 'actor'])->latest('created_at')->latest('id')->limit(8)->get(),
            'names' => $names,
            'hasSchedules' => $schedules->isNotEmpty() || OnCallSchedule::where('company_id', $companyId)->exists(),
            'canManage' => fn (OnCallSchedule $s) => OnCallScheduleResource::canEdit($s),
            'url' => fn (OnCallSchedule $s) => OnCallScheduleResource::getUrl('view', ['record' => $s]),
        ];
    }
}
