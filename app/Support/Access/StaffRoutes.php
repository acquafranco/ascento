<?php

namespace App\Support\Access;

use App\Enums\PermissionArea;
use App\Models\User;

/**
 * Rutas de la app web (fuera del panel) que puede usar un administrativo.
 * Todo lo demás de la app del técnico le queda cerrado (KeepStaffInPanel):
 * no carga visitas, fotos, reportes ni remitos como un técnico.
 */
final class StaffRoutes
{
    /** Personales: su perfil, sus avisos. */
    private const PERSONAL = [
        'profile.edit', 'profile.update',
        'push-subscriptions.store', 'push-subscriptions.destroy', 'push-subscriptions.test',
        'telegram.connect', 'telegram.disconnect',
    ];

    /** Lecturas que el panel enlaza, con el área que exigen. */
    private const READ = [
        'clients.index' => PermissionArea::Clients,
        'clients.show' => PermissionArea::Clients,
        'delivery-notes.show' => PermissionArea::Documents,
        'delivery-notes.pdf' => PermissionArea::Documents,
        'visits.show' => PermissionArea::Visits,
    ];

    public static function allows(User $user, ?string $routeName): bool
    {
        if ($routeName === null || ! Access::isActiveStaff($user)) {
            return false;
        }

        if (in_array($routeName, self::PERSONAL, true)) {
            return true;
        }

        $area = self::READ[$routeName] ?? null;

        return $area !== null && Access::can($user, $area);
    }
}
