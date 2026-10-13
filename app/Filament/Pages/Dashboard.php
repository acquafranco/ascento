<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\AdminStats;
use App\Filament\Widgets\FollowUpPanel;
use App\Filament\Widgets\PlatformOverviewWidget;
use App\Filament\Widgets\QuotesStatusChart;
use App\Filament\Widgets\StaffNotice;
use App\Filament\Widgets\WelcomeBanner;
use App\Filament\Widgets\WorkTrendChart;
use Filament\Pages\Page;

class Dashboard extends Page
{
    protected string $view = 'filament.pages.dashboard';

    public function mount($company = null): void
    {

        if ($company) {

            session([

                'selected_company_id' => $company,

            ]);

        }

    }

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
        // SuperAdmin sin empresa elegida: el estado de la plataforma (los
        // números de una empresa quedarían en 0). Con "Entrar", los de esa empresa.
        if (auth()->user()?->isSuperAdmin() && ! session('selected_company_id')) {
            return [PlatformOverviewWidget::class];
        }

        // Cada bloque decide si se ve según las áreas de quien mira (dueño: todos).
        return [
            StaffNotice::class,
            AdminStats::class,
            WorkTrendChart::class,
            QuotesStatusChart::class,
            FollowUpPanel::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return ['default' => 1, 'lg' => 2];
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
