<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Models\SubscriptionPlan;
use App\Services\Plans\PlanFeatureManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Planes (Etapa 9.2), solo para el SuperAdmin: qué funciones incluye cada
 * plan. El precio y los límites se muestran, pero no se editan acá.
 * Guarda PlanFeatureManager (lo verifica de nuevo en el servidor).
 */
class PlanFeatures extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Planes';

    protected static ?int $navigationSort = 85;

    protected static ?string $slug = 'planes';

    protected static ?string $title = 'Planes y funciones';

    protected string $view = 'filament.pages.plan-features';

    /** @var array<string, array<string, bool>> plan => función => incluida */
    public array $features = [];

    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->fillFromPlans();
    }

    private function fillFromPlans(): void
    {
        $this->features = SubscriptionPlan::offered()->mapWithKeys(fn (SubscriptionPlan $plan) => [
            $plan->slug => collect(PlanFeature::toggleable())->mapWithKeys(fn (PlanFeature $f) => [$f->value => $plan->allows($f)])->all(),
        ])->all();
    }

    public function getSubheading(): ?string
    {
        return 'Qué funciones incluye cada plan. El precio y los límites no se cambian desde acá. Quitar una función no borra datos: las empresas dejan de verla hasta que vuelvan a tenerla.';
    }

    /** Cambios pendientes respecto de lo guardado. @return list<string> */
    public function pendingChanges(): array
    {
        $lines = [];
        foreach (SubscriptionPlan::offered() as $plan) {
            foreach (PlanFeature::toggleable() as $feature) {
                $wanted = (bool) ($this->features[$plan->slug][$feature->value] ?? false);
                if ($wanted !== $plan->allows($feature)) {
                    $lines[] = $plan->shortName().': '.($wanted ? 'suma' : 'quita').' «'.$feature->label().'»';
                }
            }
        }

        return $lines;
    }

    public function saveAction(): Action
    {
        return Action::make('save')
            ->label('Guardar cambios')
            ->icon('heroicon-o-check')
            ->requiresConfirmation()
            ->modalHeading('Cambiar las funciones de los planes')
            ->modalDescription(fn () => ($changes = $this->pendingChanges())
                ? implode(' · ', $changes).'. Afecta a todas las empresas de esos planes desde ahora; no se borra ningún dato.'
                : 'No hay cambios para guardar.')
            ->modalSubmitActionLabel('Guardar')
            ->action(function () {
                $manager = app(PlanFeatureManager::class);
                $count = 0;
                foreach (SubscriptionPlan::offered() as $plan) {
                    $enabled = array_keys(array_filter($this->features[$plan->slug] ?? []));
                    $result = $manager->update(Auth::user(), $plan, $enabled);
                    $count += count($result['added']) + count($result['removed']);
                }
                $this->fillFromPlans();
                Notification::make()->title($count ? 'Planes actualizados' : 'Sin cambios')->success()->send();
            });
    }

    protected function getViewData(): array
    {
        return [
            'plans' => SubscriptionPlan::offered(),
            'toggleable' => PlanFeature::toggleable(),
            'structural' => PlanFeature::structural(),
            'limits' => PlanLimit::cases(),
        ];
    }
}
