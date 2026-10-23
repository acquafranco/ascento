<?php

namespace App\Filament\Resources\Claims\Pages;

use App\Filament\Resources\Claims\ClientClaimResource;
use Filament\Resources\Pages\ListRecords;

class ListClientClaims extends ListRecords
{
    protected static string $resource = ClientClaimResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que llega desde afuera: reclamos y pedidos de las administraciones desde el portal, y avisos de quien usa el ascensor desde el QR. No son emergencias. Lo que cargan tus técnicos está en «Reportes de técnicos».';
    }
}
