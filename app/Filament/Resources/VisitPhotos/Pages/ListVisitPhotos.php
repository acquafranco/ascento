<?php

namespace App\Filament\Resources\VisitPhotos\Pages;

use App\Filament\Resources\VisitPhotos\VisitPhotoResource;
use Filament\Resources\Pages\ListRecords;

class ListVisitPhotos extends ListRecords
{
    protected static string $resource = VisitPhotoResource::class;

    protected ?string $subheading = 'Fotos de antes y después de todas las visitas y órdenes de la empresa. Solo las ven la oficina y los técnicos de cada trabajo: nunca el cliente.';
}
