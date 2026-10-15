<?php

namespace App\Filament\Resources\Emergency\Pages;

use App\Filament\Resources\Emergency\EmergencyRequestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEmergencyRequest extends ViewRecord
{
    protected static string $resource = EmergencyRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [EmergencyRequestResource::closeAction()];
    }
}
