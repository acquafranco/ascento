<?php

namespace App\Filament\Resources\StaffUsers\Pages;

use App\Enums\PlanLimit;
use App\Filament\Concerns\ChecksPlanLimitOnCreate;
use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Services\Staff\StaffManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateStaffUser extends CreateRecord
{
    use ChecksPlanLimitOnCreate;

    protected static PlanLimit $planLimit = PlanLimit::AdminUsers;

    protected static string $resource = StaffUserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return app(StaffManager::class)->create(auth()->user(), $data, $data['levels'] ?? []);
    }

    protected function afterCreate(): void
    {
        $this->record->sendVerificationSafely();
    }

    protected function getRedirectUrl(): string
    {
        return StaffUserResource::getUrl('index');
    }
}
