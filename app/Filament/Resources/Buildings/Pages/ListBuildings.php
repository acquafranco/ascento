<?php

namespace App\Filament\Resources\Buildings\Pages;

use App\Enums\PlanLimit;
use App\Filament\Concerns\ShowsPlanUsage;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Buildings\Tables\BuildingsTable;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListBuildings extends ListRecords
{
    use ShowsPlanUsage;

    protected static PlanLimit $planLimit = PlanLimit::Buildings;

    protected static string $resource = BuildingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            // Asignación masiva visible sin tener que tildar edificios primero.
            BuildingsTable::assignAction(),
            CreateAction::make(),
        ];
    }
}
