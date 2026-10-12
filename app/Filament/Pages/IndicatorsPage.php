<?php

namespace App\Filament\Pages;

use App\Enums\PermissionArea;
use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Services\Insights\Indicators;
use App\Support\Access\Access;
use App\Support\Plans\PlanUpsell;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/** Indicadores para el dueño: cada número responde una pregunta. */
class IndicatorsPage extends Page
{
    /** Área de permisos de administrativos (Etapa 6). */
    public static function permissionArea(): ?PermissionArea
    {
        return PermissionArea::Indicators;
    }

    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Indicators;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Indicadores';

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'indicadores';

    protected static ?string $title = 'Indicadores';

    protected string $view = 'filament.pages.indicators';

    /** Mes de los indicadores del período («AAAA-MM»); se valida en Indicators::month. */
    #[Url(as: 'mes')]
    public ?string $period = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Dueño y SuperAdmin, como siempre; un administrativo, con el área habilitada.
        return Access::can($user, PermissionArea::Indicators);
    }

    public function getSubheading(): ?string
    {
        return 'Cómo viene tu empresa. Cada número responde una pregunta concreta.';
    }

    protected function getViewData(): array
    {
        $company = PlanUpsell::currentCompany();

        $month = Indicators::month($this->period);

        return [
            // Calculado para quien mira: un administrativo solo recibe los números de sus áreas.
            'sections' => $company ? app(Indicators::class)->sections($company, auth()->user(), $month->format('Y-m')) : ['basic' => [], 'period' => null, 'company' => null, 'advanced' => null],
            'month' => $month->format('Y-m'),
            'months' => Indicators::periodOptions(),
            // Los planes son del dueño: a un administrativo no se le ofrece mejorar el plan.
            'showUpsell' => ! Access::isStaff(auth()->user()),
            'upsell' => [
                'company' => PlanUpsell::url(feature: PlanFeature::CompanyIndicators),
                'advanced' => PlanUpsell::url(feature: PlanFeature::AdvancedIndicators),
            ],
        ];
    }
}
