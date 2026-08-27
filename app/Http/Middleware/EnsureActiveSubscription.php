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

        if (!$user || !$user->company) {
            abort(403);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        // La página de suscripción SIEMPRE debe quedar accesible.
        if ($request->routeIs('filament.ascensores_app.pages.subscription')) {
            return $next($request);
        }

        // El logout SIEMPRE debe quedar accesible.
        if ($request->routeIs('filament.ascensores_app.auth.logout')) {
            return $next($request);
        }

        $subscription = $user->company->subscription;

        if (
            !$subscription ||
            !in_array($subscription->status, [
                'authorized',
                'active',
                'trialing',
            ], true)
        ) {
            return redirect()->to('/admin/subscription');
        }

        return $next($request);
    }
}
