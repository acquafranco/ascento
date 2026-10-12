<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

/**
 * Ficha CRM del cliente (Etapa 6, PR 4). Solo lectura: alcanza con «ver» en
 * Clientes. Cada sección y cada pestaña piden además su propia área.
 */
class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string
    {
        return $this->record->name;
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Editar cliente')];
    }
}
