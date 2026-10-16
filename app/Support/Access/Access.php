<?php

namespace App\Support\Access;

use App\Enums\PermissionArea;
use App\Enums\PlanLimit;
use App\Models\User;
use App\Support\RequestMemo;

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

    public const BLOCK_DEACTIVATED = 'deactivated';

    public const BLOCK_COMPANY = 'company_inactive';

    public const BLOCK_PLAN = 'plan_limit';

    /** Administrativo con acceso vigente: sin ningún motivo de bloqueo. */
    public static function isActiveStaff(?User $user): bool
    {
        return self::isStaff($user) && self::staffBlockReason($user) === null;
    }

    /**
     * Por qué un administrativo no puede entrar (null: puede).
     *
     * - deactivated: el dueño lo desactivó (se conserva todo).
     * - company_inactive: la empresa está deshabilitada.
     * - plan_limit: la empresa tiene más administrativos activos que los que
     *   permite su plan (por ejemplo, al bajar de plan). Siguen con acceso los
     *   N más antiguos; el resto queda suspendido, sin borrar nada. Se calcula
     *   en cada pedido: al subir de plan o desactivar a otro, vuelve solo.
     */
    public static function staffBlockReason(User $user): ?string
    {
        if ($user->trashed()) {
            return self::BLOCK_DEACTIVATED;
        }

        // Se calcula una vez por pedido y administrativo (antes, en cada verificación de permisos).
        return RequestMemo::remember('staff-block:'.$user->id.':'.(int) $user->company_id, function () use ($user) {
            $company = RequestMemo::company($user->company_id ? (int) $user->company_id : null);

            if (! $company || ! $company->is_active) {
                return self::BLOCK_COMPANY;
            }

            $max = $company->plan()->limit(PlanLimit::AdminUsers);

            if ($max !== null) {
                $ahead = User::withoutGlobalScopes()
                    ->where('company_id', $company->id)->where('role', User::ROLE_STAFF)->whereNull('deleted_at')
                    ->where('id', '<', $user->id)->count();

                if ($ahead >= $max) {
                    return self::BLOCK_PLAN;
                }
            }

            return null;
        });
    }

    /** Texto para el administrativo y para el dueño. */
    public static function blockMessage(?string $reason, ?int $max = null): ?string
    {
        return match ($reason) {
            self::BLOCK_DEACTIVATED => 'Tu acceso fue desactivado por el dueño de la empresa.',
            self::BLOCK_COMPANY => 'La empresa está deshabilitada en Ascento.',
            self::BLOCK_PLAN => ($max === 0
                ? 'El plan actual de la empresa no incluye usuarios administrativos'
                : 'El plan actual de la empresa permite '.($max === 1 ? '1 usuario administrativo' : "{$max} usuarios administrativos").' y hay más activos')
                .': tu acceso queda suspendido hasta que el dueño cambie el plan o desactive a otro administrativo. No se borró nada.',
            default => null,
        };
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
