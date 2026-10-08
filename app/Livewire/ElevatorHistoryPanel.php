<?php

namespace App\Livewire;

use App\Enums\PlanFeature;
use App\Models\Elevator;
use App\Services\Insights\ElevatorHistory;
use App\Services\Insights\FailureAnalysis;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Historial operativo + análisis de fallas del legajo.
 * - Básico (todos los planes): últimos hechos.
 * - Avanzado (Profesional+): todo, con filtros y materiales; análisis de fallas.
 * El equipo se vuelve a buscar con el scope de empresa en cada request.
 */
class ElevatorHistoryPanel extends Component
{
    #[Locked]
    public int $elevatorId;

    public string $type = '';

    public string $months = '';

    public function mount(Elevator $elevator): void
    {
        $this->elevatorId = $elevator->id;
    }

    public function render(ElevatorHistory $history, FailureAnalysis $failures)
    {
        $elevator = Elevator::with('building.company')->findOrFail($this->elevatorId);
        $company = PlanUpsell::currentCompany();
        $guard = $company ? PlanGuard::for($company) : null;
        $advanced = (bool) $guard?->allows(PlanFeature::ElevatorHistoryAdvanced);
        $analysis = (bool) $guard?->allows(PlanFeature::FailureAnalysis);

        $type = array_key_exists($this->type, ElevatorHistory::TYPES) ? $this->type : null;
        $months = in_array((int) $this->months, [3, 6, 12, 24], true) ? (int) $this->months : null;

        return view('livewire.elevator-history-panel', [
            'elevator' => $elevator,
            'events' => $history->events($elevator, $advanced, $type, $months),
            'counts' => $history->counts($elevator),
            'advanced' => $advanced,
            'analysis' => $analysis ? $failures->forElevator($elevator) : null,
            'upsellHistory' => PlanUpsell::url(feature: PlanFeature::ElevatorHistoryAdvanced),
            'upsellAnalysis' => PlanUpsell::url(feature: PlanFeature::FailureAnalysis),
        ]);
    }
}
