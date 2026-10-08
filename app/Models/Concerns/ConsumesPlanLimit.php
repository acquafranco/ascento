<?php

namespace App\Models\Concerns;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Support\Plans\PlanGuard;

/**
 * Última línea de defensa de los límites del plan: al crear o reactivar un
 * registro que ocupa cupo, si la empresa ya está en el tope se lanza
 * PlanLimitReachedException (que se muestra como mensaje de upgrade).
 *
 * Las pantallas chequean ANTES y explican; esto cubre cualquier otro camino
 * (requests manipulados, otras acciones, código futuro).
 *
 * Solo aplica a acciones de usuarios de la empresa: el SuperAdmin (soporte)
 * y los procesos sin usuario (consola, seeds) no se limitan.
 */
trait ConsumesPlanLimit
{
    /** Qué límite consume este registro (null = este registro no consume). */
    abstract public function planLimit(): ?PlanLimit;

    protected static function bootConsumesPlanLimit(): void
    {
        static::creating(fn ($model) => $model->guardPlanLimit());

        if (method_exists(static::class, 'restoring')) {
            static::restoring(fn ($model) => $model->guardPlanLimit());
        }
    }

    protected function guardPlanLimit(): void
    {
        $actor = auth()->user();

        if (! $actor || $actor->isSuperAdmin()) {
            return;
        }

        $limit = $this->planLimit();

        // Usuarios nuevos reciben la empresa del actor en otro hook: se usa la del actor.
        $companyId = $this->company_id ?? $actor->company_id;

        if (! $limit || ! $companyId) {
            return;
        }

        $company = Company::find($companyId);

        if ($company) {
            PlanGuard::for($company)->ensureCanAdd($limit);
        }
    }
}
