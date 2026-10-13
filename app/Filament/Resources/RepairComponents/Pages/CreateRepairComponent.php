<?php

namespace App\Filament\Resources\RepairComponents\Pages;

use App\Enums\PlanFeature;
use App\Filament\Concerns\RequiresPlanFeature;
use App\Filament\Resources\RepairComponents\RepairComponentResource;
use App\Services\Repairs\RepairService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateRepairComponent extends CreateRecord
{
    use RequiresPlanFeature;

    protected static PlanFeature $planFeature = PlanFeature::Repairs;

    protected static string $resource = RepairComponentResource::class;

    protected static ?string $title = 'Registrar componente en reparación';

    protected function handleRecordCreation(array $data): Model
    {
        return app(RepairService::class)->create(auth()->user(), $data, array_values($data['files'] ?? []));
    }

    protected function getRedirectUrl(): string
    {
        return RepairComponentResource::getUrl('view', ['record' => $this->record]);
    }
}
