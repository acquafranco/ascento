<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceService extends EditRecord
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected ?string $subheading = 'Los cambios de importe aplican a los cobros que se generen desde ahora; los ya generados no cambian.';
}
