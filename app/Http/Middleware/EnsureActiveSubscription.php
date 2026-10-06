<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el uso de Ascento cuando la empresa no tiene acceso vigente
 * (ver Company::hasActiveAccess()). Se aplica al panel /admin y a la app
 * web de la empresa (/{empresa}/...).
 *
 * - SuperAdmin: acceso total.
 * - Admin sin acceso: solo la pantalla de suscripción (para pagar/reactivar).
 * - Técnico sin acceso: pantalla informativa, sin funciones operativas.
 */
class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Todo usuario normal debe pertenecer a una empresa (activa).
        $company = $user->company;

        if (! $company) {
            abort(403);
        }

        // La página de suscripción y el logout del panel SIEMPRE quedan
        // accesibles: son la salida para reactivar el servicio.
        if ($request->routeIs(
            'filament.ascensores_app.pages.subscription',
            'filament.ascensores_app.auth.logout',
        )) {
            return $next($request);
        }

        if ($company->hasActiveAccess()) {
            return $next($request);
        }

        if ($user->isAdmin()) {
            return redirect()->to('/admin/subscription');
        }

        return response()->view('subscription-inactive', [
            'company' => $company,
        ], 403);
    }
}
