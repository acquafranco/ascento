<?php

namespace App\Filament\Widgets;

use App\Support\Access\Access;
use Filament\Widgets\Widget;

class WelcomeBanner extends Widget
{
    /** Números de toda la empresa: dueño y SuperAdmin (un administrativo, no por ahora). */
    public static function canView(): bool
    {
        return ! Access::isStaff(auth()->user());
    }

    protected string $view = 'filament.widgets.welcome-banner';

    protected int|string|array $columnSpan = 'full';
}
