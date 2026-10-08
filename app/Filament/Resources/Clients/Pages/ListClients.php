<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Resources\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListClients extends ListRecords
{
    use \App\Filament\Concerns\ShowsPlanUsage;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Clients;

    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->planUsageAction(),
            CreateAction::make(),
        ];
    }
}
