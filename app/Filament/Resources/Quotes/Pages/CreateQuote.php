<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Resources\Pages\CreateRecord;

class CreateQuote extends CreateRecord
{
    use \App\Filament\Concerns\RequiresPlanFeature;

    protected static \App\Enums\PlanFeature $planFeature = \App\Enums\PlanFeature::Quotes;

    protected static string $resource = QuoteResource::class;
    protected function mutateFormDataBeforeCreate(array $data): array
{
    $data['created_by'] = auth()->id();
    unset($data['amount']); // el total sale de los ítems

    return $data;
}

    protected function afterCreate(): void
    {
        $this->record->refreshTotal();
    }
}
