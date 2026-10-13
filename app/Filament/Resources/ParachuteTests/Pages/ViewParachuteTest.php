<?php

namespace App\Filament\Resources\ParachuteTests\Pages;

use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewParachuteTest extends ViewRecord
{
    protected static string $resource = ParachuteTestResource::class;

    public function getTitle(): string
    {
        return 'Prueba de paracaídas · '.($this->record->elevator?->label ?? '').' · '.$this->record->performed_on->format('d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Corregir')];
    }
}
