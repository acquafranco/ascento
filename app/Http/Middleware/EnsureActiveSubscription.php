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

        // Administradores pueden acceder aunque todavía no tengan suscripción.
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $subscription = $user->company->subscription;

        if (!$subscription) {
            return redirect()->route('filament.ascensores_app.pages.subscription');
        }

        if (
            !in_array($subscription->status, [
                'authorized',
                'active',
                'trialing',
            ], true)
        ) {
            return redirect()->route('filament.ascensores_app.pages.subscription');
        }

        return $next($request);
    }
}
