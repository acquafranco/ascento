<?php

namespace App\Filament\Resources\RepairComponents\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\RepairComponents\RepairComponentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepairComponents extends ListRecords
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Repairs;

    protected static string $resource = RepairComponentResource::class;

    protected static ?string $title = 'Componentes en reparación';

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Registrar componente')];
    }
}
