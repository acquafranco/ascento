<?php

namespace Tests\Feature\Panel;

use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Menú del administrador: cada sección con su propio icono (antes Clientes,
 * Edificios, Mantenimientos, Inspecciones, Órdenes, Remitos y Reportes
 * compartían el mismo). Solo cambia el dibujo: mismas secciones y permisos.
 */
class NavigationIconsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** @return array<string, string> etiqueta => icono */
    private function menuIcons(): array
    {
        $tenant = $this->makeTenant();
        $this->giveCompanyPlan($tenant['company'], 'empresa');
        $this->actingInPanel($tenant['admin']);
        $this->get(Dashboard::getUrl())->assertOk(); // arma la navegación como en el panel real

        $icons = [];
        foreach (Filament::getNavigation() as $group) {
            foreach ($group->getItems() as $item) {
                /** @var NavigationItem $item */
                $icon = $item->getIcon();
                $icons[$item->getLabel()] = $icon instanceof \BackedEnum ? $icon->value : (string) $icon;
            }
        }

        return $icons;
    }

    public function test_each_section_of_the_admin_menu_has_its_own_icon(): void
    {
        $icons = $this->menuIcons();

        foreach (['Clientes', 'Edificios', 'Mantenimientos', 'Inspecciones', 'Órdenes de trabajo', 'Remitos', 'Reportes de técnicos', 'Guardias', 'Pruebas de paracaídas'] as $section) {
            $this->assertArrayHasKey($section, $icons, "Falta «{$section}» en el menú");
        }

        $repeated = array_filter(array_count_values(array_filter($icons)), fn ($n) => $n > 1);
        $this->assertSame([], $repeated, 'Iconos repetidos en el menú: '.json_encode(array_keys($repeated)));
    }
}
