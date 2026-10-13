<?php

namespace App\Filament\Widgets\Concerns;

use App\Enums\PermissionArea;
use App\Models\Company;
use App\Support\Access\AreaGate;
use App\Support\Plans\PlanUpsell;

/**
 * Bloques del inicio (Etapa 6): una empresa elegida y las áreas de quien
 * mira. canView() se vuelve a verificar en cada pedido de Livewire de un
 * administrativo (PanelAccessHook): un permiso quitado a mitad de sesión
 * corta también las actualizaciones del bloque.
 */
trait DashboardBlock
{
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
