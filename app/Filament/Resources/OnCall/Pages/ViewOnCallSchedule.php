<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOnCallSchedule extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    public function getBreadcrumbs(): array
    {
        return [OnCallScheduleResource::getUrl('index') => 'Guardias', OnCallScheduleResource::getUrl('schedules') => 'Horarios', $this->getRecordTitle()];
    }

    protected function getHeaderActions(): array
    {
        // Lo frecuente primero; cambiar el horario y dejar de usarla, agrupados.
        return [
            OnCallActions::assign()->color('primary'),
            ActionGroup::make([EditAction::make()->label('Cambiar horario o rotación'), OnCallActions::finishSchedule()])
                ->label('Más')->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
        ];
    }
}
