<?php

namespace App\Filament\Widgets;

use App\Enums\PermissionArea as Area;
use App\Enums\PlanFeature;
use App\Filament\Widgets\Concerns\DashboardBlock;
use App\Services\Insights\DashboardSummary;
use App\Support\Plans\PlanGuard;
use Filament\Widgets\Widget;

/** Seguimiento comercial del inicio: tareas, contactos, presupuestos por vencer y alertas. */
class FollowUpPanel extends Widget
{
    use DashboardBlock;

    protected static ?int $sort = 4;

    protected string $view = 'filament.widgets.follow-up-panel';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $company = static::company();

        if ($company === null) {
            return false;
        }

        $plan = PlanGuard::for($company);

        return static::allowsAny(Area::Tasks, Area::Contacts)
            || ($plan->allows(PlanFeature::Quotes) && static::gate()->allows(Area::Quotes))
            || ($plan->allows(PlanFeature::AttentionCenter) && static::gate()->allows(Area::Attention));
    }

    protected function getViewData(): array
    {
        return ['data' => app(DashboardSummary::class)->followUp(static::company(), auth()->user())];
    }
}
