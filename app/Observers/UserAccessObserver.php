<?php

namespace App\Observers;

use App\Enums\PermissionArea;
use App\Models\User;
use App\Services\Activity\ActivityLogger;

/**
 * Cambios de rol y de permisos de un usuario (Etapa 6): quién lo hizo (el
 * autor del registro), a quién afectó (el usuario, con su nombre) y cuándo.
 * Solo los nombres de rol y las claves de permisos: nunca contraseñas ni
 * tokens.
 */
class UserAccessObserver
{
    public function updated(User $user): void
    {
        if ($user->wasChanged('role')) {
            ActivityLogger::record('user.role_changed', $user, [
                'role' => ['from' => $user->getOriginal('role'), 'to' => $user->role],
            ], $user->name);
        }

        if ($user->wasChanged('permissions')) {
            $before = array_values(array_intersect((array) $this->decode($user->getOriginal('permissions')), PermissionArea::keys()));
            $after = array_values(array_intersect((array) ($user->permissions ?? []), PermissionArea::keys()));

            if ($before !== $after) {
                ActivityLogger::record('user.permissions_changed', $user, [
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                ], $user->name);
            }
        }
    }

    private function decode(mixed $value): array
    {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }
}
