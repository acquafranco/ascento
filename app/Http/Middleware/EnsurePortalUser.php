<?php

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Support\HomeRedirect;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal del cliente: solo usuarios con rol "client", de un cliente vigente,
 * de una empresa con el servicio activo. Cualquier otro usuario va a su
 * pantalla (nunca ve el portal).
 */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isClientUser()) {
            return HomeRedirect::to();
        }

        $client = $user->client;
        $company = $user->company;

        if (! $client || $client->trashed() || ! $company || (int) $client->company_id !== (int) $company->id) {
            return HomeRedirect::to(); // cuenta sin destino válido: cierra sesión
        }

        // Suscripción vencida o plan sin portal (Inicial, o tras bajar de
        // plan): ninguna pantalla ni descarga del portal, aunque la cuenta exista.
        if (! $company->hasActiveAccess() || ! $company->plan()->allows(PlanFeature::ClientPortal)) {
            return response()->view('portal.unavailable', ['company' => $company], 403);
        }

        return $next($request);
    }
}
