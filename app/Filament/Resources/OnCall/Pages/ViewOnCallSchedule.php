<?php

namespace App\Filament\Resources\OnCall\Pages;

use App\Filament\Resources\OnCall\OnCallActions;
use App\Filament\Resources\OnCall\OnCallScheduleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewOnCallSchedule extends ViewRecord
{
    protected static string $resource = OnCallScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [OnCallActions::assign(), EditAction::make(), OnCallActions::finishSchedule()];
    }
}
