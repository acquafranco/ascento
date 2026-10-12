<?php

namespace App\Filament\Resources\Inspections\Pages;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Inspections\InspectionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('deliveryNote')
                ->label('Ver remito')
                ->icon('heroicon-o-document-text')
                ->color('gray')
                ->url(fn () => DeliveryNoteResource::getUrl('view', ['record' => $this->record])),
        ];
    }

    /**
     * Las firmas (base64, pueden pesar cientos de KB) y el token público
     * no son campos del formulario: no tienen por qué viajar al navegador
     * en el estado de Livewire. Las firmas se muestran vía el infolist.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['signature'], $data['client_signature'], $data['public_token']);

        return $data;
    }
}
