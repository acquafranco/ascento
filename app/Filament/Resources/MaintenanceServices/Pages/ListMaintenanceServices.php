<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceServices extends ListRecords
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected ?string $subheading = 'Lo que cada cliente paga por el mantenimiento periódico. Las visitas y mantenimientos son parte del servicio: se cobra el servicio, no cada visita.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo servicio')];
    }
}
