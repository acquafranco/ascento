<?php

namespace App\Support\Access;

use App\Filament\Widgets\Concerns\DashboardBlock;
use Filament\Pages\Page;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Pages\ViewRecord;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Widgets\Widget;
use Livewire\ComponentHook;

/**
 * Cada pedido de Livewire de un administrativo vuelve a verificar el acceso
 * a la página del panel (no solo al abrirla): un permiso quitado a mitad de
 * sesión, o un pedido armado a mano con el estado de otra página, no sirve.
 * Dueño, SuperAdmin, técnicos y portal: nada cambia.
 */
class PanelAccessHook extends ComponentHook
{
    public function hydrate(): void
    {
        $user = auth()->user();

        if (! Access::isStaff($user)) {
            return;
        }

        $component = $this->component;

        $allowed = match (true) {
            $component instanceof ResourcePage => $this->resourcePageAllowed($component),
            $component instanceof RelationManager => $component->pageClass
                && $component->pageClass::getResource()::canAccess()
                && (! method_exists($component, 'permissionArea') || Access::can($user, $component::permissionArea())),
            $component instanceof Page => $component::canAccess(),
            // Bloques del inicio: verifican sus áreas en cada pedido y, sin
            // permiso, se dibujan vacíos y sin datos (DashboardBlock), en vez
            // de un error. Cualquier otro widget: su canView() o 403.
            $component instanceof Widget => in_array(DashboardBlock::class, class_uses_recursive($component), true) || $component::canView(),
            default => true, // componentes que no son del panel (campanita, etc.): sus propias reglas
        };

        abort_unless($allowed && Access::panelUser($user), 403);
    }

    private function resourcePageAllowed(ResourcePage $page): bool
    {
        $resource = $page::getResource();

        if (! $resource::canAccess()) {
            return false;
        }

        return match (true) {
            $page instanceof EditRecord => $resource::canEdit($page->getRecord()),
            $page instanceof ViewRecord => $resource::canView($page->getRecord()),
            $page instanceof CreateRecord => $resource::canCreate(),
            default => true,
        };
    }
}
