<?php

namespace App\Services\OnCall;

use App\Enums\PlanFeature;
use App\Models\OnCallSchedule;
use App\Models\OnCallShift;
use App\Models\User;
use App\Support\Plans\PlanGuard;
use Carbon\Carbon;

/** ¿El técnico está de guardia ahora? (su turno vigente y el horario de la guardia cubre este momento) */
final class TechnicianOnCall
{
    /** @return array{shift: OnCallShift, schedule: OnCallSchedule, until: Carbon}|null */
    public static function activeNow(User $user): ?array
    {
        if (! $user->company_id || ! PlanGuard::companyAllows((int) $user->company_id, PlanFeature::OnCall)) {
            return null;
        }

        $now = now();
        $shifts = OnCallShift::withoutGlobalScopes()->where('company_id', $user->company_id)->where('user_id', $user->id)->active()
            ->where('starts_at', '<=', $now)->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->with(['schedule' => fn ($q) => $q->withoutGlobalScopes()])->orderBy('starts_at')->get();

        foreach ($shifts as $shift) {
            if ($shift->schedule && ($window = $shift->schedule->currentWindow($now))) {
                return ['shift' => $shift, 'schedule' => $shift->schedule, 'until' => $shift->ends_at ? $window[1]->min($shift->ends_at) : $window[1]];
            }
        }

        return null;
    }
}
