<?php

namespace App\Filament\Resources\ServiceCatalogItems\Pages;

use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceCatalogItems extends ListRecords
{
    protected static string $resource = ServiceCatalogItemResource::class;

    protected ?string $subheading = 'El catálogo: lo que ofrecés y su precio de referencia. No genera cobros por sí solo; se contrata en «Servicios contratados» o desde la ficha del cliente o del edificio, con el precio que acuerdes.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo servicio ofrecido')];
    }
}
