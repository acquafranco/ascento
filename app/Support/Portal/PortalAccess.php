<?php

namespace App\Support\Portal;

use App\Models\Building;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Qué puede ver un usuario del portal del cliente. Única regla, usada por
 * todas las pantallas y descargas del portal:
 *
 * 1. El edificio tiene que estar autorizado explícitamente para ese usuario,
 *    pertenecer a SU cliente y a la empresa de mantenimiento del usuario.
 * 2. El registro (reporte, remito, presupuesto, documento) tiene que estar
 *    marcado por la empresa como "compartido con el cliente".
 *
 * Si algo no se cumple: 404 (no se confirma que exista).
 */
class PortalAccess
{
    /** @return Collection<int, int> */
    public static function buildingIds(User $user): Collection
    {
        if (! $user->isClientUser() || ! $user->client_id) {
            return collect();
        }

        // Sin memo: un cambio de permisos rige en la request siguiente.
        return $user->portalBuildings()
            ->where('buildings.company_id', $user->company_id)
            ->where('buildings.client_id', $user->client_id)
            ->whereNull('buildings.deleted_at')
            ->pluck('buildings.id');
    }

    public static function canSeeBuilding(User $user, int $buildingId): bool
    {
        return static::buildingIds($user)->contains($buildingId);
    }

    public static function ensureBuilding(User $user, Building $building): void
    {
        abort_unless(static::canSeeBuilding($user, (int) $building->id), 404);
    }

    /** Registro compartido de un edificio autorizado (y de la misma empresa). */
    public static function ensureShared(User $user, Model $record, int $buildingId): void
    {
        abort_unless(
            (int) $record->getAttribute('company_id') === (int) $user->company_id
                && (bool) $record->getAttribute('shared_with_client')
                && static::canSeeBuilding($user, $buildingId),
            404
        );
    }
}
