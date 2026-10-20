<?php

namespace App\Filament\Resources\Claims\Pages;

use App\Filament\Resources\Claims\ClientClaimResource;
use Filament\Resources\Pages\ViewRecord;

class ViewClientClaim extends ViewRecord
{
    protected static string $resource = ClientClaimResource::class;

    public function getTitle(): string
    {
        return ($this->getRecord()->isFromQr() ? 'Reporte ' : 'Reclamo ').$this->getRecord()->reference();
    }

    protected function getHeaderActions(): array
    {
        return ClientClaimResource::manageActions();
    }
}
