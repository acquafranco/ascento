<?php

namespace App\Filament\Resources\Elevators\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\Elevators\ElevatorResource;
use Filament\Resources\Pages\ListRecords;

class ListElevators extends ListRecords
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::ElevatorFile;

    protected static string $resource = ElevatorResource::class;

    public function getSubheading(): ?string
    {
        return 'Se crean solos con los ascensores y montacargas de cada edificio.';
    }
}
