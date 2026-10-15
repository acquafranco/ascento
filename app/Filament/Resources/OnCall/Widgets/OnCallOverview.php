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
 * Lo primero que se ve en «Guardias»: quién está de guardia ahora, quién en
 * una fecha y hora dada, las próximas franjas (7 días) y los últimos cambios.
 * Todo de la empresa actual; sin abrir cada guardia.
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

        $at = null;
        if (filled($this->at)) {
            try {
                $at = Carbon::parse($this->at);
            } catch (Throwable) {
                $at = null;
            }
        }

        // Próximas franjas de las guardias activas (7 días), con quién las cubre al empezar.
        $upcoming = OnCallSchedule::where('company_id', $companyId)->where('is_active', true)->get()
            ->flatMap(fn (OnCallSchedule $schedule) => collect($schedule->windowsBetween(now(), now()->addDays(7)))
                ->filter(fn ($w) => $w[1]->gt(now()))
                ->map(fn ($w) => ['schedule' => $schedule, 'start' => $w[0], 'end' => $w[1],
                    'names' => $names($service->shiftsAt($schedule, $w[0]->max(now()))), 'needed' => (int) $schedule->technicians_needed]))
            ->sortBy(fn ($row) => $row['start']->timestamp)->take(12)->values();

        return [
            'now' => $service->onDutyAt($companyId, now()),
            'query' => $at ? $service->onDutyAt($companyId, $at) : null,
            'queryAt' => $at,
            'upcoming' => $upcoming,
            'changes' => OnCallChange::where('company_id', $companyId)->with(['schedule', 'fromUser', 'toUser', 'actor'])->latest('created_at')->latest('id')->limit(5)->get(),
            'names' => $names,
            'hasSchedules' => OnCallSchedule::where('company_id', $companyId)->exists(),
            'url' => fn (OnCallSchedule $s) => OnCallScheduleResource::getUrl('view', ['record' => $s]),
        ];
    }
}
