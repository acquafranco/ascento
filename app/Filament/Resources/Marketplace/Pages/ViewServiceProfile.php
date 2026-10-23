<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\ServiceModeration;
use App\Filament\Resources\Marketplace\ServiceProfileResource;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceProfile extends ViewRecord
{
    protected static string $resource = ServiceProfileResource::class;

    protected function getHeaderActions(): array
    {
        return ServiceModeration::profileActions();
    }
}
