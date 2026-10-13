<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\DashboardBlock;
use App\Services\Insights\DashboardSummary;
use App\Support\Access\Access;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Filament\Widgets\Widget;

/** Un administrativo sin ningún bloque del inicio: a qué módulos puede entrar. */
class StaffNotice extends Widget
{
    use DashboardBlock;

    protected static ?int $sort = 0;

    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.staff-notice';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $user = auth()->user();
        $company = static::company();

        return Access::isStaff($user) && $company !== null
            && ! app(DashboardSummary::class)->hasAnyBlock($company, $user);
    }

    protected function getViewData(): array
    {
        // Los mismos enlaces del menú (ya filtrados por permisos), sin el inicio.
        $links = collect(Filament::getNavigation())
            ->flatMap(fn ($group) => $group->getItems())
            ->filter(fn (NavigationItem $item) => $item->isVisible() && $item->getUrl() !== Filament::getUrl())
            ->map(fn (NavigationItem $item) => ['label' => $item->getLabel(), 'url' => $item->getUrl()])
            ->values()->all();

        return ['links' => $links];
    }
}
