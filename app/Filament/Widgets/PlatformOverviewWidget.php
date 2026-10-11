<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

/** Inicio del SuperAdmin (sin empresa elegida): estado de la plataforma. */
class PlatformOverviewWidget extends Widget
{
    protected string $view = 'filament.widgets.platform-overview';

    protected int|string|array $columnSpan = 'full';

    // Pocas consultas: se muestra junto con la página.
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }
}
