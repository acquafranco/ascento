<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionArea as Area;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\Concerns\DashboardBlock;
use App\Services\Insights\DashboardSummary;
use App\Support\Access\Access;
use App\Support\Realtime;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Tarjetas de «Hoy» del inicio (DashboardSummary::cards): el dueño las ve
 * todas; un administrativo, solo las de sus áreas. Se actualizan solas:
 * cuando cambia una orden, un remito o un presupuesto de la empresa llega
 * "dashboard.counters" por el canal privado del dueño y el widget se vuelve
 * a calcular. Respaldo por si un evento se pierde: cada 2 min con tiempo
 * real y cada 60 s sin él.
 */
class AdminStats extends StatsOverviewWidget
{
    use DashboardBlock;

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        return static::company() !== null
            && static::allowsAny(Area::WorkOrders, Area::Documents, Area::Quotes, Area::Receivables, Area::Buildings);
    }

    protected function getPollingInterval(): ?string
    {
        return Realtime::enabled() ? '120s' : '60s';
    }

    /** @return array<string, string> */
    protected function getListeners(): array
    {
        $user = auth()->user();

        return ($user && Realtime::enabled()
            ? ['echo-private:App.Models.User.'.$user->id.',.dashboard.counters' => '$refresh']
            : []) + (Access::isStaff($user) ? [Dashboard::ACCESS_CHANGED => '$refresh'] : []);
    }

    protected function getColumns(): int|array
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        return collect(app(DashboardSummary::class)->cards(auth()->user()))
            ->map(fn (array $card) => Stat::make($card['label'], $card['value'])
                ->description($card['description'])
                ->descriptionIcon($card['icon'])
                ->color($card['color'])
                ->url($card['url'])
                ->extraAttributes(['data-card' => $card['key']]))
            ->all();
    }
}
