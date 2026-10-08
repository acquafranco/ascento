<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Services\Insights\AttentionCenter;
use App\Support\Plans\PlanUpsell;
use Filament\Pages\Page;

/** Centro de atención: lo que requiere una acción hoy. */
class AttentionCenterPage extends Page
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::AttentionCenter;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'Centro de atención';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'atencion';

    protected static ?string $title = 'Centro de atención';

    protected string $view = 'filament.pages.attention-center';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $user->isSuperAdmin());
    }

    public function getSubheading(): ?string
    {
        return 'Solo lo que necesita que hagas algo. Si está vacío, está todo en orden.';
    }

    protected function getViewData(): array
    {
        $company = PlanUpsell::currentCompany();

        return [
            'sections' => $company ? app(AttentionCenter::class)->sections($company) : ['basic' => collect(), 'advanced' => null, 'alerts' => null],
            'upsell' => [
                'advanced' => PlanUpsell::url(feature: PlanFeature::AttentionAdvanced),
                'alerts' => PlanUpsell::url(feature: PlanFeature::AdvancedAlerts),
            ],
        ];
    }
}
