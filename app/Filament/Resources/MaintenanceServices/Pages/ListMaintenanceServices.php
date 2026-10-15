<?php

namespace App\Filament\Resources\MaintenanceServices\Pages;

use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMaintenanceServices extends ListRecords
{
    protected static string $resource = MaintenanceServiceResource::class;

    protected ?string $subheading = 'Lo que tiene contratado cada cliente y genera sus cobros. Se cobra el servicio, no cada visita. Los servicios que ofrecés y sus precios de referencia están en «Servicios ofrecidos».';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Contratar servicio')];
    }
}
