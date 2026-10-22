<?php

namespace App\Support\Marketplace;

use App\Enums\PermissionArea;
use App\Models\User;
use App\Support\Access\Access;

/**
 * Quién compra repuestos por una empresa de mantenimiento: el dueño y los
 * administrativos con «Stock» (ver para mirar, gestionar para pedir y
 * aceptar). Ni técnicos, ni portal, ni proveedores, ni el SuperAdmin (que
 * modera desde sus pantallas y no pide en nombre de una empresa).
 */
final class BuyerAccess
{
    public static function canBrowse(?User $user): bool
    {
        return $user !== null && ! $user->isSuperAdmin() && $user->company_id !== null
            && (Access::isOwner($user) || (Access::isActiveStaff($user) && Access::can($user, PermissionArea::Stock)));
    }

    public static function canOrder(?User $user): bool
    {
        return self::canBrowse($user)
            && (Access::isOwner($user) || Access::can($user, PermissionArea::Stock, PermissionArea::MANAGE));
    }
}
