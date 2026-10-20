<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use App\Filament\Resources\OnCall\Widgets\OnCallOverview;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Livewire\Attributes\On;

/**
 * Portada de «Guardias»: arriba, quién está de guardia ahora y quién sigue
 * (OnCallOverview), con las dos acciones frecuentes (asignar y reemplazar)
 * a mano; el calendario y el historial, plegados; abajo, las guardias
 * configuradas (cada una con sus turnos, cambios y trabajos).
 */
class ListOnCallSchedules extends ListRecords
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected ?string $subheading = 'Quién cubre fuera del horario habitual, ahora y después.';

    protected function getHeaderActions(): array
    {
        return [
            OnCallActions::assignAny(),
            OnCallActions::replaceAny(),
            CreateAction::make()->label('Nueva guardia')->color('gray')->icon('heroicon-o-plus'),
        ];
    }

    /** Botón «Asignar» de una guardia sin cobertura (widget de la portada). */
    #[On('on-call-assign')]
    public function openAssign(?int $schedule = null): void
    {
        $this->mountAction('assignOnCall', ['schedule' => $schedule]);
    }

    /** Botón «Reemplazar» de un técnico de guardia (widget de la portada). */
    #[On('on-call-replace')]
    public function openReplace(?int $shift = null): void
    {
        $this->mountAction('replaceOnCall', ['shift' => $shift]);
    }

    protected function getHeaderWidgets(): array
    {
        return [OnCallOverview::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
