<?php

namespace App\Filament\Resources\DeliveryNotes\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewDeliveryNote extends ViewRecord
{
    protected static string $resource = DeliveryNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
