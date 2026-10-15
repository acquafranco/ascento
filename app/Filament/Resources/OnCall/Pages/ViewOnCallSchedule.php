<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOnCallSchedule extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::OnCall;

    protected static string $resource = OnCallScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [OnCallActions::assign(), EditAction::make(), OnCallActions::finishSchedule()];
    }
}
