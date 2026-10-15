<?php

namespace App\Services\Location;

use App\Models\Company;
use App\Models\TechnicianLocation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ubicación de los técnicos.
 *
 * - Solo el propio técnico activa, desactiva o manda SU posición: la
 *   identidad y la empresa salen siempre del usuario autenticado.
 * - Se guarda solo la última posición (sin recorridos). Al desactivar o
 *   cerrar sesión se borran las coordenadas; pasadas unas horas sin
 *   novedades, también (prune).
 * - Nunca se inventa una posición: sin datos nuevos, la última queda con su
 *   hora y el mapa la muestra como desactualizada.
 * - Las coordenadas no van a logs, avisos ni URLs.
 */
final class TechnicianLocationService
{
    public static function canTrack(?User $user): bool
    {
        return $user instanceof User && $user->role === 'technician' && $user->company_id !== null && ! $user->trashed();
    }

    public function of(User $user): ?TechnicianLocation
    {
        return TechnicianLocation::withoutGlobalScopes()->where('user_id', $user->id)->first();
    }

    private function row(User $user): TechnicianLocation
    {
        $row = $this->of($user) ?? new TechnicianLocation;
        $row->forceFill(['user_id' => $user->id, 'company_id' => (int) $user->company_id]);

        return $row;
    }

    private function save(TechnicianLocation $row): void
    {
        TechnicianLocation::withoutEvents(fn () => $row->save());
    }

    public function enable(User $technician, ?string $permission = null): TechnicianLocation
    {
        abort_unless(self::canTrack($technician), 403);
        $row = $this->row($technician);
        $row->forceFill([
            'tracking_enabled' => true,
            'enabled_at' => $row->tracking_enabled ? $row->enabled_at : now(),
            'permission' => self::permission($permission) ?? $row->permission,
            'last_error' => null,
            'last_error_at' => null,
        ]);
        $this->save($row);

        return $row;
    }

    /** Deja de recibir posiciones y borra la última (no queda dónde estaba). */
    public function disable(User $technician): TechnicianLocation
    {
        abort_unless(self::canTrack($technician), 403);
        $row = $this->row($technician);
        $row->forceFill([
            'tracking_enabled' => false,
            'disabled_at' => now(),
            'latitude' => null, 'longitude' => null, 'accuracy' => null, 'located_at' => null,
            'last_error' => null, 'last_error_at' => null,
        ]);
        $this->save($row);

        return $row;
    }

    /**
     * Guarda la posición del técnico autenticado.
     *
     * @return 'stored'|'skipped'|'disabled'
     */
    public function record(User $technician, float $latitude, float $longitude, ?int $accuracy, ?string $permission = null): string
    {
        abort_unless(self::canTrack($technician), 403);
        $row = $this->of($technician);

        // Sin activación en Ascento, no se guarda nada (aunque el navegador mande).
        if (! $row?->tracking_enabled) {
            return 'disabled';
        }

        $min = (int) config('location.min_seconds_between', 20);
        if ($row->located_at && $row->located_at->gt(now()->subSeconds($min))) {
            return 'skipped';
        }

        $row->forceFill([
            'latitude' => round($latitude, 7),
            'longitude' => round($longitude, 7),
            'accuracy' => $accuracy,
            'located_at' => now(),
            'permission' => 'granted',
            'last_error' => null,
            'last_error_at' => null,
        ]);
        $this->save($row);

        return 'stored';
    }

    /** Permiso del navegador o un problema conocido, sin coordenadas. */
    public function report(User $technician, ?string $permission, ?string $error): void
    {
        abort_unless(self::canTrack($technician), 403);
        $row = $this->of($technician);
        if (! $row?->tracking_enabled) {
            return;
        }
        $permission = self::permission($permission);
        $row->forceFill(array_filter([
            'permission' => $permission,
            'last_error' => in_array($error, TechnicianLocation::ERRORS, true) ? $error : null,
            'last_error_at' => in_array($error, TechnicianLocation::ERRORS, true) ? now() : null,
        ], fn ($v) => $v !== null));
        $this->save($row);
    }

    /** Al cerrar sesión: no queda una posición de alguien que ya no está usando Ascento. */
    public function forget(User $user): void
    {
        TechnicianLocation::withoutGlobalScopes()->where('user_id', $user->id)->whereNotNull('latitude')
            ->update(['latitude' => null, 'longitude' => null, 'accuracy' => null, 'located_at' => null, 'updated_at' => now()]);
    }

    /** Borra las coordenadas sin novedades por más de keep_hours. Devuelve cuántas. */
    public function prune(): int
    {
        return TechnicianLocation::withoutGlobalScopes()
            ->whereNotNull('latitude')
            ->where('located_at', '<', now()->subHours((int) config('location.keep_hours', 12)))
            ->update(['latitude' => null, 'longitude' => null, 'accuracy' => null, 'located_at' => null, 'updated_at' => now()]);
    }

    /**
     * Técnicos de UNA empresa con su estado, para el mapa del administrador.
     * Dos consultas en total (técnicos + ubicaciones), sin historial.
     *
     * @return Collection<int, array>
     */
    public function forCompany(Company $company): Collection
    {
        $technicians = User::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->where('role', 'technician')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'avatar', 'company_id', 'role']);

        $locations = TechnicianLocation::withoutGlobalScopes()
            ->where('company_id', $company->id)
            ->whereIn('user_id', $technicians->pluck('id'))
            ->get()->keyBy('user_id');

        $approximate = (int) config('location.approximate_meters', 150);

        return $technicians->map(function (User $tech) use ($locations, $approximate) {
            $loc = $locations->get($tech->id);
            $status = $loc?->status() ?? TechnicianLocation::DISABLED;
            // Solo se manda la posición si está activado y tiene una (vigente o vieja, marcada como tal).
            $position = $loc && $loc->tracking_enabled && $loc->hasPosition() && $status !== TechnicianLocation::PERMISSION_DENIED
                ? ['lat' => $loc->latitude, 'lng' => $loc->longitude, 'accuracy' => $loc->accuracy, 'approximate' => $loc->accuracy !== null && $loc->accuracy > $approximate]
                : null;

            return [
                'id' => $tech->id,
                'name' => $tech->name,
                'initials' => $tech->initials(),
                'photo' => $tech->photoUrl(),
                'status' => $status,
                'statusLabel' => TechnicianLocation::STATUS_LABELS[$status],
                'fresh' => $status === TechnicianLocation::ONLINE,
                'locatedAt' => $loc?->located_at?->toIso8601String(),
                'locatedLabel' => $loc?->located_at ? $loc->located_at->translatedFormat('d/m H:i').' ('.$loc->located_at->diffForHumans().')' : null,
                'position' => $position,
            ];
        })->values();
    }

    private static function permission(?string $permission): ?string
    {
        return in_array($permission, ['granted', 'prompt', 'denied', 'unsupported'], true) ? $permission : null;
    }
}
