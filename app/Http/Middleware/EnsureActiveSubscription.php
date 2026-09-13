<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            abort(403);
        }

        // El SuperAdmin no necesita empresa ni suscripción.
        // Tiene acceso total al sistema.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // Todo usuario normal debe pertenecer a una empresa.
        if (!$user->company) {
            abort(403);
        }

        // La página de suscripción SIEMPRE debe quedar accesible.
        if ($request->routeIs('filament.ascensores_app.pages.subscription')) {
            return $next($request);
        }

        // El logout SIEMPRE debe quedar accesible.
        if ($request->routeIs('filament.ascensores_app.auth.logout')) {
            return $next($request);
        }

        $company = $user->company;

        // Si ya existe alguna suscripción (manual o de Mercado Pago),
        // su estado manda — sin importar si el trial gratuito todavía
        // no venció. Pausar/cancelar tiene que poder cortar el acceso
        // aunque falten días de trial.
        $subscription = $company->latestSubscription;

        if ($subscription) {
            if (in_array($subscription->status, [
                'authorized',
                'active',
                'trialing',
            ], true)) {
                return $next($request);
            }

            return redirect()->to('/admin/subscription');
        }

        // Nunca tuvo ninguna suscripción: acá sí importa el trial
        // gratuito de 30 días manejado por Ascento.
        if ($company->onTrial()) {
            return $next($request);
        }

        return redirect()->to('/admin/subscription');
    }
}
