<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\ServiceProfile;
use App\Models\User;
use App\Support\Access\Access;

/**
 * Quién puede hacer qué en «Profesionales y servicios».
 *
 * - Dueño de un perfil: los usuarios de esa cuenta de proveedor, o el dueño
 *   (admin) de esa empresa de mantenimiento. Un mismo login ofrece productos
 *   y servicios: no hace falta otra cuenta.
 * - Pedir contacto: los mismos que pueden pedir cotizaciones de repuestos en
 *   su empresa (BuyerAccess::canOrder); ver las propias: BuyerAccess::canBrowse.
 * - El SuperAdmin modera, pero no es dueño de ningún perfil.
 */
class ServiceAccess
{
    /** ¿Puede tener (o editar) un perfil profesional? */
    public static function canOwnProfile(?User $user): bool
    {
        return $user !== null && ! $user->isSuperAdmin()
            && (($user->isSupplierUser() && $user->supplier_id !== null) || Access::isOwner($user));
    }

    /** El perfil de la cuenta del usuario (de su proveedor o de su empresa), si existe. */
    public static function profileOf(?User $user): ?ServiceProfile
    {
        if (! self::canOwnProfile($user)) {
            return null;
        }

        return $user->isSupplierUser()
            ? ServiceProfile::where('supplier_id', $user->supplier_id)->first()
            : ServiceProfile::where('company_id', $user->company_id)->first();
    }

    public static function owns(?User $user, ServiceProfile $profile): bool
    {
        if (! self::canOwnProfile($user)) {
            return false;
        }

        return $user->isSupplierUser()
            ? $profile->supplier_id !== null && (int) $profile->supplier_id === (int) $user->supplier_id
            : $profile->company_id !== null && (int) $profile->company_id === (int) $user->company_id;
    }

    public static function canRequest(?User $user): bool
    {
        return BuyerAccess::canOrder($user);
    }

    public static function canSeeSent(?User $user): bool
    {
        return BuyerAccess::canBrowse($user);
    }
}
