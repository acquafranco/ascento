<?php

namespace App\Support\Portal;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Responsables que ve el cliente en el portal (Etapa 8.3): los técnicos
 * asignados (mantenimiento o inspección) a SUS edificios autorizados de la
 * empresa activa. Nunca toda la plantilla de la empresa: un técnico sin
 * relación con esos edificios no aparece. Solo foto, nombre, rol y
 * edificios; el contacto que se muestra es el de la empresa, nunca los datos
 * personales del técnico. Solo lectura.
 */
class PortalResponsibles
{
    public const ROLES = ['maintenance' => 'Mantenimiento', 'inspection' => 'Inspección'];

    /**
     * @param  array<int, int>|null  $onlyBuildingIds  para un edificio puntual (ya autorizado)
     *                                                 Una fila por técnico (aunque atienda varios edificios). «roles» es la
     *                                                 unión; «buildings», cada edificio con SUS roles (las asignaciones
     *                                                 vigentes), para no atribuirle en un edificio un rol que tiene en otro.
     * @return Collection<int, array{user: User, roles: list<string>, buildings: list<array{name: string, roles: list<string>}>, same_roles: bool}>
     */
    public static function for(User $viewer, ?array $onlyBuildingIds = null): Collection
    {
        $companyId = PortalAccess::currentCompanyId($viewer);
        if (! $viewer->isClientUser() || $companyId === null) {
            return collect();
        }

        $allowed = PortalAccess::buildingIds($viewer, $companyId)->map(fn ($id) => (int) $id);
        if ($onlyBuildingIds !== null) {
            $allowed = $allowed->intersect(array_map('intval', $onlyBuildingIds));
        }
        if ($allowed->isEmpty()) {
            return collect();
        }

        $rows = DB::table('building_user')
            ->join('users', 'users.id', '=', 'building_user.user_id')
            ->join('buildings', 'buildings.id', '=', 'building_user.building_id')
            ->whereIn('building_user.building_id', $allowed->values()->all())
            ->whereIn('building_user.type', array_keys(self::ROLES))
            ->where('users.role', 'technician')
            ->where('users.company_id', $companyId)
            ->whereNull('users.deleted_at')
            ->where('buildings.company_id', $companyId)
            ->whereNull('buildings.deleted_at')
            ->orderBy('buildings.name')
            ->get(['building_user.user_id', 'building_user.type', 'building_user.building_id', 'buildings.name as building_name']);

        if ($rows->isEmpty()) {
            return collect();
        }

        $users = User::query()->whereIn('id', $rows->pluck('user_id')->unique())->get(['id', 'name', 'avatar', 'company_id', 'role'])->keyBy('id');

        return $rows->groupBy('user_id')
            ->filter(fn ($own, $id) => $users->has($id))
            ->map(function (Collection $own, $id) use ($users) {
                $buildings = $own->groupBy('building_id')->map(fn (Collection $b) => [
                    'name' => (string) $b->first()->building_name,
                    'roles' => self::labels($b->pluck('type')),
                ])->values();

                return [
                    'user' => $users->get($id),
                    'roles' => self::labels($own->pluck('type')),
                    'buildings' => $buildings->all(),
                    'same_roles' => $buildings->pluck('roles')->unique(fn ($r) => implode('|', $r))->count() === 1,
                ];
            })
            ->sortBy(fn (array $r) => mb_strtolower($r['user']->name))
            ->values();
    }

    /** @return list<string> roles en orden fijo (mantenimiento, inspección) */
    private static function labels(Collection $types): array
    {
        return collect(self::ROLES)->filter(fn ($label, $type) => $types->contains($type))->values()->all();
    }

    /** ¿Es responsable de alguno de los edificios que esta persona ve en el portal? */
    public static function isResponsibleFor(User $viewer, User $technician): bool
    {
        return self::for($viewer)->contains(fn (array $r) => $r['user']->is($technician));
    }

    /** Foto por la ruta del portal (con la versión vigente), o null. */
    public static function photoUrl(User $technician): ?string
    {
        return $technician->avatar ? route('portal.responsible-photo', ['user' => $technician->id, 'v' => $technician->photoVersion()]) : null;
    }
}
