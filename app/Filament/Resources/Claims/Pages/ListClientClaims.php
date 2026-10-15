<?php

namespace App\Filament\Resources\Claims\Pages;

use App\Filament\Resources\Claims\ClientClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListClientClaims extends ListRecords
{
    protected static string $resource = ClientClaimResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que tus clientes reportan desde el portal. No son emergencias: el portal les indica usar la alarma del ascensor y el 911.';
    }
}
