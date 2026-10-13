<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\PermissionArea;
use App\Filament\Pages\Dashboard;
use App\Models\Company;
use App\Support\Access\Access;
use App\Support\Access\AreaGate;
use App\Support\Plans\PlanUpsell;
use Illuminate\Contracts\View\View;

/**
 * Bloques del inicio (Etapa 6): una empresa elegida y las áreas de quien
 * mira. En cada pedido de Livewire, el servidor vuelve a verificar
 * canView(): si a un administrativo le quitaron el permiso a mitad de
 * sesión, el bloque se dibuja vacío (se retira de la pantalla, sin recargar
 * ni mostrar un error) y no calcula ni envía sus datos. Los bloques se
 * actualizan cuando el inicio detecta que cambiaron los permisos
 * (Dashboard::checkAccess).
 */
trait DashboardBlock
{
    /**
     * Filament corta con un 403 (y Livewire muestra su aviso de error) si
     * canView() es falso. Acá, en cambio, el bloque sin permiso se retira
     * vacío: la verificación se hace igual en cada pedido, en render() (y en
     * los gráficos, antes de calcular sus datos).
     */
    public function bootCanAuthorizeAccess(): void
    {
        $this->hasAuthorizedAccess = true;
    }

    public function render(): View
    {
        if (! static::canView()) {
            return view('filament.widgets.dashboard-block-removed');
        }

        return parent::render();
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        // Solo los administrativos reciben el aviso de cambio de permisos.
        return Access::isStaff(auth()->user()) ? [Dashboard::ACCESS_CHANGED => '$refresh'] : [];
    }

    protected static function company(): ?Company
    {
        return PlanUpsell::currentCompany();
    }

    protected static function gate(): AreaGate
    {
        return AreaGate::for(auth()->user());
    }

    /** ¿Tiene «ver» en alguna de estas combinaciones de áreas? */
    protected static function allowsAny(PermissionArea|array ...$options): bool
    {
        $gate = static::gate();

        foreach ($options as $areas) {
            if ($gate->allows(...(is_array($areas) ? $areas : [$areas]))) {
                return true;
            }
        }

        return false;
    }
}
