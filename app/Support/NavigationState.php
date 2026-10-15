<?php

namespace App\Support;

use App\Filament\Resources\Emergency\EmergencyRequestResource;
use App\Models\User;

/**
 * Preferencias del menú plegable (solo visual). Se guardan en el navegador,
 * con una clave propia de cada usuario y panel: no contiene datos, solo los
 * nombres de las categorías que la persona dejó abiertas.
 */
final class NavigationState
{
    /** Clave del navegador: usuario + panel, sin exponer el id (HMAC con la clave de la app). */
    public static function storageKey(User $user, string $panel): string
    {
        return 'ascento.menu.v1.'.$panel.'.'.substr(hash_hmac('sha256', 'menu|'.$user->getKey(), (string) config('app.key')), 0, 20);
    }

    /**
     * Opciones del menú que, si tienen un número, no pueden quedar escondidas:
     * su categoría se muestra abierta (pedidos de ayuda abiertos).
     *
     * @return list<string>
     */
    public static function urgentUrls(): array
    {
        try {
            return [EmergencyRequestResource::getUrl('index')];
        } catch (\Throwable) {
            return [];
        }
    }
}
