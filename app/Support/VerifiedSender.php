<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Correos que Ascento manda a terceros en nombre de una empresa
 * (presupuestos, invitaciones y enlaces del portal): las cuentas creadas
 * desde que existe la verificación tienen que confirmar su correo antes.
 * Evita que alguien se registre con un correo ajeno y use Ascento para
 * mandar mails a otros.
 *
 * - Cuentas anteriores a la fecha de corte: nunca se bloquean.
 * - SuperAdmin y procesos del sistema (sin usuario): no aplica.
 * - Todo lo demás (cargar datos, PDF, enlaces para copiar) sigue igual.
 */
class VerifiedSender
{
    public const MESSAGE = 'Para enviar correos a tus clientes, primero confirmá tu correo con el enlace que te mandamos (podés pedir uno nuevo desde el aviso de arriba).';

    public static function required(?User $actor): bool
    {
        $since = config('auth.verified_sender_since');

        return $actor !== null
            && ! $actor->isSuperAdmin()
            && ! $actor->hasVerifiedEmail()
            && filled($since)
            && $actor->created_at !== null
            && $actor->created_at->gte(Carbon::parse($since));
    }

    /** @throws ValidationException */
    public static function ensure(?User $actor, string $field = 'email'): void
    {
        if (static::required($actor)) {
            throw ValidationException::withMessages([$field => static::MESSAGE]);
        }
    }
}
