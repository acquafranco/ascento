<?php

namespace App\Filament\Resources\ClientInteractions\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\ClientInteractions\ClientInteractionResource;
use App\Services\Crm\ClientInteractionService;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditClientInteraction extends EditRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ClientContacts;

    protected static string $resource = ClientInteractionResource::class;

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(ClientInteractionService::class)->update(auth()->user(), $record, $data);
    }

    protected function getRedirectUrl(): string
    {
        return ClientInteractionResource::getUrl('index');
    }
}
