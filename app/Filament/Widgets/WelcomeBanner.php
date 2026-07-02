<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;

class WelcomeBanner extends Widget
{
    protected string $view = 'filament.widgets.welcome-banner';
    protected int|string|array $columnSpan = 'full';

}
