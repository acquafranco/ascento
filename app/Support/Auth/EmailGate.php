<?php

namespace App\Support\Auth;

use App\Models\User;
use App\Services\Onboarding\CompanySetup;
use App\Support\Access\Access;
use Carbon\Carbon;

/**
 * Correo confirmado para usar el servicio: el dueño de una empresa nueva
 * (registrada desde «auth.verified_owner_since») hace la configuración inicial
 * guiada y, al terminarla (u omitirla o pausarla), queda en «Verificá tu
 * email» hasta confirmar. No entra al panel ni a la app.
 *
 * No aplica a técnicos, administrativos ni al portal (no reciben este correo:
 * los crea la empresa, y el portal se activa con su propio enlace), al
 * SuperAdmin ni a las empresas anteriores a la fecha de corte.
 */
final class EmailGate
{
    /** ¿Esta cuenta tiene que confirmar su correo para usar el servicio? */
    public static function appliesTo(?User $user): bool
    {
        $since = config('auth.verified_owner_since');

        return Access::isOwner($user)
            && filled($since)
            && $user->company !== null
            && $user->company->setup_status !== null // empresa registrada con la configuración inicial
            && $user->company->created_at !== null
            && $user->company->created_at->gte(Carbon::parse($since));
    }

    /** ¿Hay que frenarla AHORA? (sin confirmar y con la configuración inicial ya cerrada). */
    public static function blocks(?User $user): bool
    {
        return self::appliesTo($user) && ! $user->hasVerifiedEmail() && ! CompanySetup::isActiveFor($user);
    }
}
