<?php

namespace App\Filament\Resources\StaffUsers\Pages;

use App\Enums\PlanLimit;
use App\Filament\Concerns\ChecksPlanLimitOnCreate;
use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Services\Staff\StaffManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateStaffUser extends CreateRecord
{
    use ChecksPlanLimitOnCreate;

    protected static PlanLimit $planLimit = PlanLimit::AdminUsers;

    protected static string $resource = StaffUserResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        return $this->onEmailField(fn () => app(StaffManager::class)->create(auth()->user(), $data, $data['levels'] ?? []));
    }

    /** El error de correo del servicio se muestra en el campo del formulario. */
    private function onEmailField(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ValidationException $e) {
            if (isset($e->errors()['email'])) {
                throw ValidationException::withMessages(['data.email' => $e->errors()['email']]);
            }

            throw $e;
        }
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
