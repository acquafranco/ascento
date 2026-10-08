<?php

namespace App\Exceptions;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Support\Plans\PlanGuard;
use RuntimeException;

/**
 * Se intentó superar un límite del plan por un camino sin chequeo previo.
 * Se muestra como mensaje claro con opción de actualizar el plan (ver
 * bootstrap/app.php), nunca como error genérico.
 */
class PlanLimitReachedException extends RuntimeException
{
    public function __construct(public readonly Company $company, public readonly PlanLimit $limit)
    {
        parent::__construct(PlanGuard::for($company)->limitReachedMessage($limit));
    }
}
