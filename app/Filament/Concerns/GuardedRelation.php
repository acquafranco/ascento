<?php

namespace App\Filament\Concerns;

use App\Enums\PermissionArea;
use App\Support\Access\Access;

/**
 * Relación dentro de una ficha con área propia (Etapa 6). Para un
 * administrativo, la pestaña solo aparece con «ver» en ESA área (no en la de
 * la ficha: por ejemplo, las cobranzas dentro de un cliente piden
 * «Cobranzas»), y sus acciones piden «gestionar» en esa área (ActionGuard).
 * Se aplica en OwnerRecordOfCurrentCompany::canViewForRecord (junto con la
 * empresa del registro). Para el dueño y el SuperAdmin no cambia nada.
 */
trait GuardedRelation
{
    abstract public static function permissionArea(): ?PermissionArea;

    public static function relationAreaAllows(): bool
    {
        $user = auth()->user();

        return ! Access::isStaff($user) || (static::permissionArea() !== null && Access::can($user, static::permissionArea()));
    }
}
