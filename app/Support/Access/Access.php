<?php

namespace App\Support\Access;

use App\Enums\PermissionArea;
use App\Models\Company;
use App\Models\User;

/**
 * Único lugar que decide quién puede qué dentro del panel (Etapa 6).
 *
 * - SuperAdmin: todo, como hoy (sus filtros de empresa no cambian).
 * - Dueño (role = admin): todo, como hoy. «Dueño» es exactamente isAdmin().
 * - Administrativo (role = staff) con acceso vigente: solo las áreas que le
 *   habilitó el dueño (gestionar incluye ver). Nunca lo reservado al dueño.
 * - Técnicos y portal: nada del panel.
 *
 * Todas las comprobaciones del servidor (recursos, páginas, acciones,
 * rutas, archivos, Livewire) pasan por acá.
 */
final class Access
{
    public static function isOwner(?User $user): bool
    {
        return $user !== null && $user->isAdmin() && ! $user->isSuperAdmin() && $user->company_id !== null;
    }

    public static function isStaff(?User $user): bool
    {
        return $user !== null && $user->role === User::ROLE_STAFF && ! $user->isSuperAdmin();
    }

    /**
     * Administrativo con acceso vigente: activo, de una empresa existente y
     * habilitada. (El límite por plan se suma en el PR 2.)
     */
    public static function isActiveStaff(?User $user): bool
    {
        if (! self::isStaff($user) || $user->trashed() || $user->company_id === null) {
            return false;
        }

        return (bool) Company::whereKey($user->company_id)->value('is_active');
    }

    /** ¿Entra al panel? SuperAdmin, dueño o administrativo vigente. */
    public static function panelUser(?User $user): bool
    {
        return $user !== null && ($user->isSuperAdmin() || $user->isAdmin() || self::isActiveStaff($user));
    }

    /** ¿Puede ver o gestionar esta área? */
    public static function can(?User $user, PermissionArea $area, string $level = PermissionArea::VIEW): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isAdmin()) {
            return true;
        }

        if (! self::isActiveStaff($user)) {
            return false;
        }

        $granted = self::permissionsOf($user);

        if ($level === PermissionArea::VIEW) {
            return in_array($area->key(PermissionArea::VIEW), $granted, true)
                || ($area->hasManage() && in_array($area->key(PermissionArea::MANAGE), $granted, true));
        }

        return $area->hasManage() && in_array($area->key(PermissionArea::MANAGE), $granted, true);
    }

    /** @return list<string> claves válidas habilitadas (las desconocidas se ignoran) */
    public static function permissionsOf(User $user): array
    {
        return array_values(array_intersect((array) ($user->permissions ?? []), PermissionArea::keys()));
    }

    /** Acción de Filament o del modelo → nivel requerido. */
    public static function levelFor(string $action): string
    {
        return in_array($action, ['viewAny', 'view'], true) ? PermissionArea::VIEW : PermissionArea::MANAGE;
    }
}
