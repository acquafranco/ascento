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

    /** Marca de nivel explícita (ver ActionGuard::requires). */
    private const LEVEL_ATTRIBUTE = 'data-access-level';

    /**
     * «Ver» para ver una ficha o abrir un enlace o un modal sin acción
     * (descargar un PDF, ver un video); «gestionar» para el resto. Una
     * acción puede declarar su nivel con managing() / viewing(): por ejemplo, un enlace
     * que envía algo al cliente es «gestionar» aunque no ejecute nada acá.
     */
    public static function levelOf(Action $action): string
    {
        $declared = $action->getExtraAttributes()[self::LEVEL_ATTRIBUTE] ?? null;

        if (in_array($declared, [PermissionArea::VIEW, PermissionArea::MANAGE], true)) {
            return $declared;
        }

        return $action instanceof ViewAction || ! $action->hasAction() ? PermissionArea::VIEW : PermissionArea::MANAGE;
    }

    /** Atributos para declarar que una acción exige «gestionar» (->extraAttributes(ActionGuard::managing(), merge: true)). */
    public static function managing(): array
    {
        return [self::LEVEL_ATTRIBUTE => PermissionArea::MANAGE];
    }

    /** Ídem para «ver» (una acción de solo consulta que ejecuta algo, como generar un archivo para descargar). */
    public static function viewing(): array
    {
        return [self::LEVEL_ATTRIBUTE => PermissionArea::VIEW];
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
            // Una relación con área propia (por ejemplo, contactos dentro de Clientes) usa la suya.
            $component instanceof RelationManager && method_exists($component, 'permissionArea') => $component::class,
            $component instanceof RelationManager => $component->pageClass ? $component->pageClass::getResource() : null,
            is_object($component) => $component::class,
            default => null,
        };
    }
}
