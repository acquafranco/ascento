<?php

namespace App\Support\Portal;

use App\Models\Building;
use App\Models\PortalMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Qué puede ver un usuario del portal del cliente. Única regla, usada por
 * todas las pantallas, descargas y avisos del portal:
 *
 * 1. Una persona puede tener acceso en varias empresas de mantenimiento, una
 *    membresía (PortalMembership) por cada cliente que la autorizó. Solo
 *    cuentan las membresías activas.
 * 2. Un edificio se ve si está autorizado explícitamente para la persona y
 *    pertenece al cliente y a la empresa de una membresía activa.
 * 3. En el portal se trabaja con UNA empresa a la vez (la elegida, guardada
 *    en la sesión y validada en cada request contra las membresías).
 * 4. El registro (reporte, remito, presupuesto, documento) tiene que estar
 *    compartido con el cliente y ser de la empresa activa.
 *
 * Si algo no se cumple: 404 (no se confirma que exista). Sin memo entre
 * requests: un cambio de permisos rige en la request siguiente.
 */
class PortalAccess
{
    public const SESSION_KEY = 'portal_company_id';

    /** @return Collection<int, PortalMembership> membresías activas (más viejas primero) */
    public static function memberships(User $user): Collection
    {
        if (! $user->isClientUser() || $user->trashed()) {
            return collect();
        }

        return PortalMembership::where('user_id', $user->id)->active()
            ->whereHas('company', fn ($q) => $q->where('is_active', true))
            ->with(['company', 'client:id,name,company_id,deleted_at'])
            ->orderBy('id')->get();
    }

    /**
     * Empresa activa del portal: la elegida (si sigue siendo válida) o la
     * primera. Null si la persona no tiene ningún acceso activo.
     */
    public static function currentCompanyId(User $user): ?int
    {
        $request = app()->bound('request') ? request() : null;
        $cacheKey = 'portal_company_'.$user->id;

        if ($request && $request->attributes->has($cacheKey)) {
            return $request->attributes->get($cacheKey);
        }

        $companyIds = PortalMembership::where('user_id', $user->id)->active()->orderBy('id')->pluck('company_id')->map(fn ($id) => (int) $id);
        $selected = $request && $request->hasSession() ? (int) $request->session()->get(self::SESSION_KEY) : 0;
        $current = $companyIds->contains($selected) ? $selected : $companyIds->first();

        $request?->attributes->set($cacheKey, $current);

        return $current;
    }

    public static function currentMembership(User $user): ?PortalMembership
    {
        $companyId = static::currentCompanyId($user);

        return $companyId === null ? null : static::memberships($user)->firstWhere('company_id', $companyId);
    }

    /** Elegir otra empresa (solo una con acceso activo). */
    public static function switchTo(User $user, int $companyId): bool
    {
        if (! static::memberships($user)->contains('company_id', $companyId)) {
            return false;
        }

        request()->session()->put(self::SESSION_KEY, $companyId);
        request()->attributes->remove('portal_company_'.$user->id);

        return true;
    }

    /**
     * Edificios autorizados (de membresías activas). Con $companyId, solo los
     * de esa empresa.
     *
     * @return Collection<int, int>
     */
    public static function buildingIds(User $user, ?int $companyId = null): Collection
    {
        if (! $user->isClientUser() || $user->trashed()) {
            return collect();
        }

        return Building::withoutGlobalScopes()
            ->whereNull('buildings.deleted_at')
            ->whereIn('buildings.id', fn ($q) => $q->select('building_id')->from('client_portal_buildings')->where('user_id', $user->id))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('portal_memberships as m')
                ->where('m.user_id', $user->id)->whereNull('m.deactivated_at')
                ->whereColumn('m.company_id', 'buildings.company_id')->whereColumn('m.client_id', 'buildings.client_id'))
            ->when($companyId !== null, fn ($q) => $q->where('buildings.company_id', $companyId))
            ->pluck('buildings.id');
    }

    /** ¿Puede ver el edificio? (en cualquiera de sus empresas: lo usan los avisos). */
    public static function canSeeBuilding(User $user, int $buildingId): bool
    {
        return static::buildingIds($user)->contains($buildingId);
    }

    /** Pantallas: edificio autorizado Y de la empresa activa del portal. */
    public static function ensureBuilding(User $user, Building $building): void
    {
        abort_unless(
            (int) $building->company_id === static::currentCompanyId($user)
                && static::buildingIds($user, static::currentCompanyId($user))->contains((int) $building->id),
            404
        );
    }

    /** Registro compartido de un edificio autorizado de la empresa activa. */
    public static function ensureShared(User $user, Model $record, int $buildingId): void
    {
        $companyId = static::currentCompanyId($user);

        abort_unless(
            $companyId !== null
                && (int) $record->getAttribute('company_id') === $companyId
                && (bool) $record->getAttribute('shared_with_client')
                && static::buildingIds($user, $companyId)->contains($buildingId),
            404
        );
    }
}
