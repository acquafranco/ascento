<?php

namespace App\Filament\Resources\Receivables\Pages;

use App\Filament\Resources\Receivables\ReceivableActions;
use App\Filament\Resources\Receivables\ReceivableResource;
use Filament\Resources\Pages\ViewRecord;

class ViewReceivable extends ViewRecord
{
    protected static string $resource = ReceivableResource::class;

    public function getTitle(): string
    {
        return $this->record->concept;
    }

    protected function getHeaderActions(): array
    {
        return [ReceivableActions::pay(), ReceivableActions::void()];
    }
}
