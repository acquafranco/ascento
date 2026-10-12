<?php

namespace App\Filament\Resources\ClientInteractions\Pages;

use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Services\Crm\ClientInteractionService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateClientInteraction extends CreateRecord
{
    protected static string $resource = ClientInteractionResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(ClientInteractionService::class)->record(auth()->user(), $data);
    }

    protected function getRedirectUrl(): string
    {
        return ClientInteractionResource::getUrl('index');
    }
}
