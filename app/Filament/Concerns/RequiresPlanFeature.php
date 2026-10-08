<?php

namespace App\Filament\Concerns;

use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;

/**
 * Páginas de una funcionalidad que no está en todos los planes: si el plan
 * no la incluye, se explica y se lleva a "Ver planes" (no un 403).
 *
 * La página define: protected static PlanFeature $planFeature.
 */
trait RequiresPlanFeature
{
    public function mountRequiresPlanFeature(): void
    {
        $company = PlanUpsell::currentCompany();

        if ($company && ! PlanGuard::for($company)->allows(static::$planFeature)) {
            PlanUpsell::featureNotification($company, static::$planFeature)->send();
            $this->redirect(PlanUpsell::url(feature: static::$planFeature), navigate: false);
        }
    }
}
