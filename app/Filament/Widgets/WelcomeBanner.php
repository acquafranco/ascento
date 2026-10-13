<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class WelcomeBanner extends Widget
{
    /** Solo saluda (sin datos de la empresa): también a los administrativos. */
    public static function canView(): bool
    {
        return auth()->check();
    }

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.welcome-banner';

    protected int|string|array $columnSpan = 'full';
}
