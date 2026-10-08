<?php

namespace App\Filament\Concerns;

use App\Enums\PlanLimit;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;

/**
 * Pantallas "Crear" de algo que ocupa cupo: si la empresa está en el tope,
 * ni se abre el formulario (se explica y se ofrece actualizar el plan), y se
 * vuelve a chequear al guardar.
 *
 * La página define: protected static PlanLimit $planLimit.
 */
trait ChecksPlanLimitOnCreate
{
    public function mountChecksPlanLimitOnCreate(): void
    {
        if (! $this->planAllowsCreation()) {
            $this->redirect(PlanUpsell::url(static::$planLimit), navigate: false);
        }
    }

    protected function beforeCreate(): void
    {
        if (! $this->planAllowsCreation()) {
            $this->halt();
        }
    }

    protected function planAllowsCreation(): bool
    {
        $company = PlanUpsell::currentCompany();

        if (! $company || PlanGuard::for($company)->canAdd(static::$planLimit)) {
            return true;
        }

        PlanUpsell::limitNotification($company, static::$planLimit)->send();

        return false;
    }
}
