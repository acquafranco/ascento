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

    protected function getHeaderActions(): array
    {
        // Lo frecuente primero; editar y finalizar, agrupados.
        return [
            OnCallActions::assign(),
            ActionGroup::make([EditAction::make()->label('Editar horario o técnicos'), OnCallActions::finishSchedule()])
                ->label('Más')->icon('heroicon-m-ellipsis-vertical')->color('gray')->button(),
        ];
    }
}
