<?php

namespace App\Support\Access;

use App\Enums\PermissionArea;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Auth\Access\Response;

/**
 * Autorización por defecto de TODAS las acciones de Filament (botones,
 * acciones de tabla y masivas, modales), registrada con
 * Action::configureUsing. Las acciones propias no pasan solas por las
 * Policies: sin esto, un administrativo con «ver» podría ejecutar, por
 * ejemplo, «Asignar técnicos» con un pedido manipulado.
 *
 * - Dueño y SuperAdmin: exactamente la respuesta de siempre (la de la página).
 * - Administrativo: además, el permiso del área de la página donde está la
 *   acción. Ver una ficha o abrir un enlace pide «ver»; cualquier acción que
 *   ejecuta algo pide «gestionar». Sin área conocida: solo enlaces.
 *
 * Filament vuelve a comprobarlo en el servidor al ejecutar la acción
 * (InteractsWithActions::callMountedAction), no solo al dibujarla.
 */
final class ActionGuard
{
    public static function response(Action $action): Response
    {
        $user = auth()->user();

        if (Access::isStaff($user)) {
            $component = $action->getHasActionsLivewire();
            $level = self::levelOf($action);
            $area = self::areaOf($component);

            if ($area === null) {
                if ($level !== PermissionArea::VIEW || $action->hasAction()) {
                    return Response::deny();
                }
            } elseif (! Access::can($user, $area, $level) || ($level === PermissionArea::MANAGE && self::managedOnlyByOwner($component))) {
                return Response::deny();
            }
        }

        // Después, la respuesta de siempre de la página (la única, para el dueño y el SuperAdmin).
        return $action->getHasActionsLivewire()?->getDefaultActionAuthorizationResponse($action) ?? Response::allow();
    }

    /** «Ver» para ver una ficha o abrir un enlace o un modal sin acción; «gestionar» para el resto. */
    public static function levelOf(Action $action): string
    {
        return $action instanceof ViewAction || ! $action->hasAction() ? PermissionArea::VIEW : PermissionArea::MANAGE;
    }

    /** Área de la página, recurso o relación donde vive la acción. */
    public static function areaOf(mixed $component): ?PermissionArea
    {
        $owner = self::ownerClass($component);

        return $owner && method_exists($owner, 'permissionArea') ? $owner::permissionArea() : null;
    }

    private static function managedOnlyByOwner(mixed $component): bool
    {
        $owner = self::ownerClass($component);

        return $owner && method_exists($owner, 'managedOnlyByOwner') && $owner::managedOnlyByOwner();
    }

    /** Clase que declara el área: el recurso de la página o de la relación, o la página misma. */
    private static function ownerClass(mixed $component): ?string
    {
        return match (true) {
            $component instanceof ResourcePage => $component::getResource(),
            $component instanceof RelationManager => $component->pageClass ? $component->pageClass::getResource() : null,
            is_object($component) => $component::class,
            default => null,
        };
    }
}
