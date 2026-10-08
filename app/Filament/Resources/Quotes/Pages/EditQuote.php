<?php

namespace App\Filament\Resources\Quotes\Pages;

use App\Filament\Resources\Quotes\QuoteResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditQuote extends EditRecord
{
    use \App\Filament\Concerns\RequiresPlanFeature;

    protected static \App\Enums\PlanFeature $planFeature = \App\Enums\PlanFeature::Quotes;

    protected static string $resource = QuoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['amount'], $data['created_by'], $data['company_id']); // el total sale de los ítems

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->refreshTotal();
    }
}
