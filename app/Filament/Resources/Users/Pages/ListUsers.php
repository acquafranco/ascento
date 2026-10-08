<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    use \App\Filament\Concerns\ShowsPlanUsage;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Technicians;

    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            CreateAction::make(),
        ];
    }
}
