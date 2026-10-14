<?php

namespace App\Filament\Resources\ServiceCatalogItems\Pages;

use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use Filament\Resources\Pages\EditRecord;

class EditServiceCatalogItem extends EditRecord
{
    protected static string $resource = ServiceCatalogItemResource::class;

    protected ?string $subheading = 'Los cambios no modifican las contrataciones existentes: cada una conserva su precio acordado.';
}
