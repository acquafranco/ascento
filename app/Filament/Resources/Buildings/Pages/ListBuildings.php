<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Filament\Resources\Buildings\BuildingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBuildings extends ListRecords
{
    use \App\Filament\Concerns\ShowsPlanUsage;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Buildings;

    protected static string $resource = BuildingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            CreateAction::make(),
        ];
    }
}
