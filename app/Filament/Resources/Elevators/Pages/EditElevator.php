<?php

namespace App\Filament\Resources\Elevators\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\Elevators\ElevatorResource;
use Filament\Resources\Pages\EditRecord;

class EditElevator extends EditRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ElevatorFile;

    protected static string $resource = ElevatorResource::class;

    public function getTitle(): string
    {
        return 'Ficha técnica · '.$this->record->displayName();
    }

    protected function getRedirectUrl(): ?string
    {
        return ElevatorResource::getUrl('view', ['record' => $this->record]);
    }
}
