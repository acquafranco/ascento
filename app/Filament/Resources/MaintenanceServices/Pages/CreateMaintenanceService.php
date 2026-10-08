<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateMaintenanceService extends CreateRecord
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Servicio creado. Ya se generaron los cobros que correspondan a este mes.';
    }
}
