<?php

namespace App\Http\Middleware;

use App\Support\Auth\EmailGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * El dueño de una empresa nueva que no confirmó su correo no usa el servicio
 * (EmailGate): cualquier pantalla lo lleva a «Verificá tu email», donde puede
 * reenviar el enlace, corregir el correo o cerrar sesión. Una acción en
 * segundo plano (una pestaña vieja abierta) se rechaza.
 */
class RequireVerifiedOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! EmailGate::blocks($request->user())) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire') && ! $request->expectsJson()) {
            return redirect()->route('verification.notice');
        }

        abort(403, 'Confirmá tu correo para seguir usando Ascento.');
    }
}
