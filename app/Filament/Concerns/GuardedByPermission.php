<?php

namespace App\Filament\Concerns;

use App\Enums\PermissionArea;
use App\Support\Access\Access;
use BackedEnum;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Recurso del panel protegido por un área de permisos (Etapa 6). Para un
 * administrativo, cada operación de Filament (listar, ver, crear, editar,
 * borrar, restaurar y las masivas) exige el permiso del área: «ver» para
 * listar y ver; «gestionar» para todo lo demás. Después sigue el control de
 * siempre. Para el dueño y el SuperAdmin no cambia nada.
 */
trait GuardedByPermission
{
    /** Área del recurso (null: ningún administrativo). */
    abstract public static function permissionArea(): ?PermissionArea;

    /** Si es true, solo el dueño gestiona (el administrativo, como mucho, ve). */
    public static function managedOnlyByOwner(): bool
    {
        return false;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $user = auth()->user();

        if (Access::isStaff($user)) {
            $name = $action instanceof BackedEnum ? (string) $action->value : ($action instanceof UnitEnum ? $action->name : $action);
            $level = Access::levelFor($name);
            $area = static::permissionArea();

            if ($area === null
                || ! Access::can($user, $area, $level)
                || ($level === PermissionArea::MANAGE && static::managedOnlyByOwner())) {
                return Response::deny();
            }
        }

        return parent::getAuthorizationResponse($action, $record);
    }
}
