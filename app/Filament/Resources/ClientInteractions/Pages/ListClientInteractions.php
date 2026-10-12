<?php

namespace App\Filament\Resources\ClientInteractions\Pages;

use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClientInteractions extends ListRecords
{
    protected static string $resource = ClientInteractionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Registrar contacto')];
    }
}
