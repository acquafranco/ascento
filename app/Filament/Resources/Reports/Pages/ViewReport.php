<?php

namespace App\Filament\Resources\Reports\Pages;

use App\Filament\Resources\Reports\ReportResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReport extends ViewRecord
{
    protected static string $resource = ReportResource::class;

    protected ?string $heading = 'Detalle del reporte';

    protected ?string $subheading = 'Información completa del reporte';

    protected function getHeaderActions(): array
    {
        return [];
    }
}
