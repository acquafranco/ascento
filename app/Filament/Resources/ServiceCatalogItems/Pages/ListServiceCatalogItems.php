<?php

namespace App\Filament\Resources\ServiceCatalogItems\Pages;

use App\Filament\Resources\ServiceCatalogItems\ServiceCatalogItemResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListServiceCatalogItems extends ListRecords
{
    protected static string $resource = ServiceCatalogItemResource::class;

    protected ?string $subheading = 'Los servicios que ofrecés. Se contratan desde la ficha de cada cliente o edificio, con el precio que acuerdes.';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nuevo servicio')];
    }
}
