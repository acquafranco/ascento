<?php

namespace App\Support\Plans;

use App\Enums\PlanLimit;
use Closure;
use Filament\Actions\Action;
use Illuminate\Support\Collection;

/**
 * "Reactivar" también ocupa cupo: si no, desactivar → crear otro →
 * reactivar permitiría pasarse del límite del plan.
 */
class PlanRestoreGuard
{
    /** Para ->before() de RestoreAction (un registro). */
    public static function before(PlanLimit $limit): Closure
    {
        return fn (Action $action, mixed $record = null) => static::check($action, $limit, collect([$record]));
    }

    /** Para ->before() de RestoreBulkAction (varios registros). */
    public static function beforeBulk(PlanLimit $limit): Closure
    {
        return fn (Action $action, Collection $records) => static::check($action, $limit, $records);
    }

    private static function check(Action $action, PlanLimit $limit, Collection $records): void
    {
        $company = PlanUpsell::currentCompany();

        if (! $company) {
            return;
        }

        // Solo cuentan los que de verdad ocupan cupo (p. ej. reactivar un
        // admin no consume lugar de técnico).
        $count = $records
            ->filter(fn ($r) => $r && method_exists($r, 'trashed') && $r->trashed()
                && method_exists($r, 'planLimit') && $r->planLimit() === $limit)
            ->count();

        if ($count > 0 && ! PlanGuard::for($company)->canAdd($limit, $count)) {
            PlanUpsell::limitNotification($company, $limit)->send();
            $action->cancel();
        }
    }
}
