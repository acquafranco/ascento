<?php

namespace App\Http\Middleware;

use App\Enums\PlanFeature;
use App\Support\HomeRedirect;
use App\Support\Portal\PortalAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Portal del cliente: solo usuarios con rol "client" con al menos un acceso
 * (membresía) activo. Trabaja con la empresa activa del portal; esa empresa
 * tiene que tener el servicio vigente y un plan con portal. Cualquier otro
 * usuario va a su pantalla (nunca ve el portal).
 */
class EnsurePortalUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->isClientUser()) {
            return HomeRedirect::to();
        }

        $membership = PortalAccess::currentMembership($user);

        if (! $membership || ! $membership->client || $membership->client->trashed()
            || (int) $membership->client->company_id !== (int) $membership->company_id) {
            return HomeRedirect::to(); // sin ningún acceso vigente: cierra sesión
        }

        $company = $membership->company;

        // Datos comunes de las vistas del portal (empresa y cliente ACTIVOS).
        View::share('portalCompany', $company);
        View::share('portalClient', $membership->client);
        View::share('portalMemberships', PortalAccess::memberships($user));

        // Suscripción vencida o plan sin portal (Inicial, o tras bajar de
        // plan): ninguna pantalla ni descarga del portal de esa empresa.
        if (! $company->hasActiveAccess() || ! $company->plan()->allows(PlanFeature::ClientPortal)) {
            return response()->view('portal.unavailable', ['company' => $company], 403);
        }

        return $next($request);
    }
}
