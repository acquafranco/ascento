<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Models\PlanFeatureChange;
use App\Models\SubscriptionPlan;
use App\Services\Plans\PlanFeatureManager;
use App\Support\Plans\FeatureCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Planes (Etapa 9.2), solo para el SuperAdmin: el catálogo completo de
 * funcionalidades (FeatureCatalog) por módulo, con su origen y categoría,
 * y en qué planes está cada una. Las configurables se cambian acá (con
 * confirmación y motivo); las esenciales no se pueden quitar. Precio y
 * límites, solo lectura. Abajo, el historial de cambios.
 * Guarda PlanFeatureManager (lo vuelve a verificar en el servidor).
 */
class PlanFeatures extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Planes';

    protected static string|\UnitEnum|null $navigationGroup = 'Plataforma';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'planes';

    protected static ?string $title = 'Planes y funciones';

    protected string $view = 'filament.pages.plan-features';

    /** @var array<string, array<string, bool>> plan => función configurable => incluida */
    public array $features = [];

    public string $search = '';

    public string $module = '';

    public string $category = '';

    /** «inicial:1» = incluida en Inicial; «inicial:0» = no incluida. */
    public string $plan = '';

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
        return 'Todas las funciones de Ascento y en qué planes están. Las esenciales no se pueden quitar. El precio y los límites no se cambian desde acá. Quitar una función no borra datos: las empresas dejan de usarla hasta que vuelvan a tenerla.';
    }

    /** ¿La función está en el plan, según lo elegido en pantalla? */
    public function includes(array $entry, string $slug): ?bool
    {
        $feature = $entry['feature'] ?? $entry['partOf'];

        return match (true) {
            $entry['category'] === FeatureCatalog::ROLE => null,
            $feature === null, $feature->isEssential() => true,
            default => (bool) ($this->features[$slug][$feature->value] ?? false),
        };
    }

    /** Entradas visibles con la búsqueda y los filtros, por módulo. @return array<string, list<array>> */
    public function rows(): array
    {
        $search = mb_strtolower(trim($this->search));
        [$planSlug, $wanted] = array_pad(explode(':', $this->plan), 2, null);

        return collect(FeatureCatalog::byModule())
            ->filter(fn ($entries, $module) => $this->module === '' || $this->module === $module)
            ->map(fn (array $entries) => array_values(array_filter($entries, function (array $entry) use ($search, $planSlug, $wanted) {
                if ($this->category !== '' && $entry['category'] !== $this->category) {
                    return false;
                }
                if ($search !== '' && ! str_contains(mb_strtolower($entry['name'].' '.$entry['description'].' '.$entry['origin']), $search)) {
                    return false;
                }
                if ($planSlug) {
                    $included = $this->includes($entry, $planSlug);

                    return $included !== null && $included === ($wanted === '1');
                }

                return true;
            })))
            ->filter()
            ->all();
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
            ->schema([
                Textarea::make('context')->label('Motivo (queda en el historial)')->maxLength(250)->rows(2),
            ])
            ->modalSubmitActionLabel('Guardar')
            ->action(function (array $data) {
                $manager = app(PlanFeatureManager::class);
                $count = 0;
                foreach (SubscriptionPlan::offered() as $plan) {
                    $enabled = array_keys(array_filter($this->features[$plan->slug] ?? []));
                    $result = $manager->update(Auth::user(), $plan, $enabled, $data['context'] ?? null);
                    $count += count($result['added']) + count($result['removed']);
                }
                $this->fillFromPlans();
                Notification::make()->title($count ? 'Planes actualizados' : 'Sin cambios')->success()->send();
            });
    }

    /** @return Collection<int, PlanFeatureChange> */
    public function history(): Collection
    {
        return PlanFeatureChange::query()->latest('id')->limit(50)->get();
    }

    protected function getViewData(): array
    {
        return [
            'plans' => SubscriptionPlan::offered(),
            'limits' => PlanLimit::cases(),
            'rows' => $this->rows(),
            'modules' => FeatureCatalog::MODULES,
            'categories' => FeatureCatalog::CATEGORIES,
            'history' => $this->history(),
        ];
    }
}
