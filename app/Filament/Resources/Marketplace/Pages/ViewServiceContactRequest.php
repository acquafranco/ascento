<?php

namespace App\Filament\Resources\Marketplace\Pages;

use App\Filament\Resources\Marketplace\ServiceContactRequestResource;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;

/** Contenido de una solicitud: solo si se abrió con motivo (auditado) en los últimos minutos. */
class ViewServiceContactRequest extends Page
{
    use InteractsWithRecord;

    protected static string $resource = ServiceContactRequestResource::class;

    protected string $view = 'filament.resources.marketplace.service-contact-request';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string
    {
        return 'Solicitud '.$this->record->reference;
    }

    protected function getHeaderActions(): array
    {
        return ServiceContactRequestResource::isUnlocked($this->record) ? [] : [ServiceContactRequestResource::openAction()->record($this->record)];
    }

    protected function getViewData(): array
    {
        $unlocked = ServiceContactRequestResource::isUnlocked($this->record);

        return ['row' => $unlocked ? $this->record->load('messages') : $this->record, 'unlocked' => $unlocked];
    }
}
