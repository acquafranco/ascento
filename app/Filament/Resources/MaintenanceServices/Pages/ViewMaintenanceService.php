<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewMaintenanceService extends ViewRecord
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
