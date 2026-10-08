<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Services\Insights\Indicators;
use App\Support\Plans\PlanUpsell;
use Filament\Pages\Page;

/** Indicadores para el dueño: cada número responde una pregunta. */
class IndicatorsPage extends Page
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Indicators;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Indicadores';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'indicadores';

    protected static ?string $title = 'Indicadores';

    protected string $view = 'filament.pages.indicators';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function getSubheading(): ?string
    {
        return 'Cómo viene tu empresa. Cada número responde una pregunta concreta.';
    }

    protected function getViewData(): array
    {
        $company = PlanUpsell::currentCompany();

        return [
            'sections' => $company ? app(Indicators::class)->sections($company) : ['basic' => [], 'company' => null, 'advanced' => null],
            'upsell' => [
                'company' => PlanUpsell::url(feature: PlanFeature::CompanyIndicators),
                'advanced' => PlanUpsell::url(feature: PlanFeature::AdvancedIndicators),
            ],
        ];
    }
}
