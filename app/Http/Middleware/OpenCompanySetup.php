<?php

namespace App\Http\Middleware;

use App\Filament\Pages\CompanySetupWizard;
use App\Services\Onboarding\CompanySetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Empresa nueva con la configuración inicial pendiente: cualquier pantalla
 * del panel abre el asistente (CompanySetupWizard) hasta que el dueño lo
 * termine, lo omita o elija «Seguir después» (en esa sesión ya no se abre
 * solo). Solo navegaciones completas (GET): nunca corta una acción de Livewire.
 */
class OpenCompanySetup
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && ! $request->hasHeader('X-Livewire') && ! $request->expectsJson()
            && ! $request->routeIs(CompanySetupWizard::getRouteName(), 'filament.ascensores_app.auth.*')
            && CompanySetup::isActiveFor($request->user())) {
            return redirect()->to(CompanySetup::wizardUrl());
        }

        return $next($request);
    }
}
