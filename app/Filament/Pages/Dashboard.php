<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\WelcomeBanner;

use Filament\Pages\Page;

class Dashboard extends Page
{
    protected string $view = 'filament.pages.dashboard';

    public static function getNavigationLabel(): string
    {
        return 'Inicio';
    }

    public function getTitle(): string
    {
        return 'Panel principal';
    }

        protected function getHeaderWidgets(): array
    {
        return [
            WelcomeBanner::class,
        ];
    }
    protected function getFooterWidgets(): array
    {
        return [
            AdminStats::class,
        ];
    }
    public static function getNavigationIcon(): string
    {
        return 'heroicon-o-home';
    }

    protected function getColumns(): int|string|array

{

    return 1;

}


}

