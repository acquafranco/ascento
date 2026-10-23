<?php

namespace App\Services\Interventions;

use App\Enums\PermissionArea;
use App\Models\User;
use App\Services\Activity\ActivityLogger;
use App\Support\Access\Access;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Autorización por técnico para registrar URGENCIAS en cualquier edificio de
 * su empresa (además de sus edificios asignados y de cuando está de guardia).
 *
 * - Alcance: SOLO abrir intervenciones (InterventionService::buildingsQuery).
 *   No cambia mantenimientos, inspecciones, clientes, edificios ni órdenes
 *   ajenas, ni lo que el técnico ve en su app.
 * - Quién la otorga o revoca: el dueño de la empresa, o un administrativo
 *   activo con permiso para gestionar Guardias (el permiso existente que
 *   decide quién cubre las urgencias). Nunca el propio técnico, otro técnico,
 *   el portal ni el SuperAdmin (soporte).
 * - Registro: quién y cuándo en el usuario; cada alta y cada baja en la
 *   actividad de la empresa. Revocar vale en el pedido siguiente.
 */
class UrgentCoverage
{
    public static function canManage(?User $actor, User $technician): bool
    {
        if (! $actor || $actor->isSuperAdmin() || ! $actor->company_id || (int) $actor->company_id !== (int) $technician->company_id) {
            return false;
        }
        if ($technician->role !== 'technician' || $technician->trashed()) {
            return false;
        }

        return Access::isOwner($actor)
            || (Access::isActiveStaff($actor) && Access::can($actor, PermissionArea::OnCall, PermissionArea::MANAGE));
    }

    public function grant(User $actor, User $technician): User
    {
        return $this->change($actor, $technician, true);
    }

    public function revoke(User $actor, User $technician): User
    {
        return $this->change($actor, $technician, false);
    }

    private function change(User $actor, User $technician, bool $grant): User
    {
        if (! self::canManage($actor, $technician)) {
            throw ValidationException::withMessages(['urgent_coverage' => 'No podés cambiar esta autorización.']);
        }

        return DB::transaction(function () use ($actor, $technician, $grant) {
            $fresh = User::withoutGlobalScopes()->whereKey($technician->id)->where('company_id', $actor->company_id)->lockForUpdate()->firstOrFail();
            if ($fresh->hasUrgentCoverage() === $grant) {
                return $fresh; // ya estaba así: no se registra un cambio que no hubo
            }

            $fresh->forceFill([
                'urgent_coverage_granted_at' => $grant ? now() : null,
                'urgent_coverage_granted_by' => $grant ? $actor->id : null,
            ])->save();

            ActivityLogger::record($grant ? 'technician.urgent_coverage.granted' : 'technician.urgent_coverage.revoked', $fresh,
                ['urgencias_en_cualquier_edificio' => $grant ? 'autorizado' : 'revocado'], $fresh->name);

            return $fresh;
        });
    }
}
