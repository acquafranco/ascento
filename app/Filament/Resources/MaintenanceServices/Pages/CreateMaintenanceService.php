<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\MaintenanceServices\Schemas\ServiceAssignmentForm;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateMaintenanceService extends CreateRecord
{
    protected static string $resource = MaintenanceServiceResource::class;

    /** Contrataciones creadas en este alta (una por edificio elegido). */
    private int $created = 1;

    /** Importe recalculado en el servidor; varios edificios = una contratación por edificio. */
    protected function handleRecordCreation(array $data): Model
    {
        $records = ServiceAssignmentForm::createAll($data);
        $this->created = count($records);

        return $records[0];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return ($this->created > 1 ? "Se crearon {$this->created} contrataciones (una por edificio)." : 'Servicio creado.')
            .' Ya se generaron los cobros que correspondan a este mes.';
    }
}
