<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Schemas\ServiceAssignmentForm;
use Filament\Resources\Pages\EditRecord;

class EditMaintenanceService extends EditRecord
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected ?string $subheading = 'Los cambios de importe aplican a los cobros que se generen desde ahora; los ya generados no cambian.';

    /** Misma regla que el alta: el importe se recalcula en el servidor con el alcance y los equipos actuales. */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return ServiceAssignmentForm::prepareUpdate($data, $this->getRecord());
    }
}
