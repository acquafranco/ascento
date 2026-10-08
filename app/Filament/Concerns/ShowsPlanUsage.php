<?php

namespace App\Filament\Concerns;

use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Actions\Action;

/**
 * Listados de algo con límite: muestra "18 / 20 edificios · Plan Inicial",
 * avisa cerca del tope y ofrece "Ver planes".
 *
 * La página define: protected static PlanLimit $planLimit.
 */
trait ShowsPlanUsage
{
    public function getSubheading(): ?string
    {
        $company = PlanUpsell::currentCompany();

        if (! $company) {
            return null;
        }

        $guard = PlanGuard::for($company);

        return trim($guard->usageLabel(static::$planLimit).' · Plan '.$guard->plan()->shortName()
            .($guard->warning(static::$planLimit) ? '. '.$guard->warning(static::$planLimit) : ''));
    }

    protected function planUsageAction(): Action
    {
        return Action::make('plans')
            ->label('Ver planes')
            ->icon('heroicon-o-arrow-trending-up')
            ->color('warning')
            ->url(fn () => PlanUpsell::url(static::$planLimit))
            ->visible(function () {
                $company = PlanUpsell::currentCompany();

                return $company && PlanGuard::for($company)->isNear(static::$planLimit);
            });
    }
}
