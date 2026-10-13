<?php

namespace App\Filament\Concerns;

use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Livewire\Livewire;

/**
 * Páginas de una funcionalidad que no está en todos los planes: si el plan
 * no la incluye, se explica y se lleva a "Ver planes" (no un 403).
 *
 * Al abrir la página: aviso y «Ver planes». En los pedidos siguientes de
 * Livewire (acciones, datos, actualizaciones) se vuelve a verificar: un
 * pedido manipulado sobre una página ya abierta responde 403.
 *
 * La página define: protected static PlanFeature $planFeature.
 */
trait RequiresPlanFeature
{
    public function bootRequiresPlanFeature(): void
    {
        if (Livewire::isLivewireRequest() && ! PlanGuard::currentAllows(static::$planFeature)) {
            abort(403);
        }
    }

    public function mountRequiresPlanFeature(): void
    {
        $company = PlanUpsell::currentCompany();

        if ($company && ! PlanGuard::for($company)->allows(static::$planFeature)) {
            PlanUpsell::featureNotification($company, static::$planFeature)->send();
            $this->redirect(PlanUpsell::url(feature: static::$planFeature), navigate: false);
        }
    }
}
