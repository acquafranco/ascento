<?php

namespace App\Filament\Resources\ParachuteTests\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewParachuteTest extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ParachuteTests;

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
