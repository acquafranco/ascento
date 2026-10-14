<?php

namespace App\Http\Middleware;

use App\Services\Auth\TwoFactorAuthentication;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Segundo factor obligatorio del SuperAdmin, en CADA pedido (Etapa 11): web,
 * panel de Filament, Livewire, descargas, tiempo real. No depende del
 * formulario de ingreso, así que también cubre «Recordarme», sesiones que ya
 * estaban abiertas y cualquier otra forma de llegar a una sesión.
 *
 * - Sin 2FA configurado: solo la configuración y salir.
 * - Con 2FA, sin verificar en esta sesión: solo el desafío y salir.
 * Los demás usuarios no se ven afectados.
 */
class EnsureSuperAdminTwoFactor
{
    public function __construct(private TwoFactorAuthentication $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->requiresTwoFactor()
            || $request->routeIs('two-factor.*', 'logout', 'filament.ascensores_app.auth.logout')) {
            return $next($request);
        }

        $target = match (true) {
            ! $user->hasConfirmedTwoFactor() => 'two-factor.setup',
            ! $this->twoFactor->passed($user) => 'two-factor.challenge',
            default => null,
        };

        if ($target === null) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson() && ! $request->hasHeader('X-Livewire')) {
            return redirect()->route($target);
        }

        abort(403, 'Falta verificar el segundo factor.');
    }
}
