<?php

namespace App\Filament\Resources\Elevators\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\Elevators\ElevatorResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewElevator extends ViewRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ElevatorFile;

    protected static string $resource = ElevatorResource::class;

    public function getTitle(): string
    {
        return $this->record->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [EditAction::make()->label('Editar ficha')];
    }
}
