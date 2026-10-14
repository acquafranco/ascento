<?php

namespace App\Filament\Resources\ServiceCatalogItems\Pages;

use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceCatalogItem extends CreateRecord
{
    protected static string $resource = ServiceCatalogItemResource::class;
}
