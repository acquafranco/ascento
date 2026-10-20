<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\PlanLimit;
use App\Filament\Concerns\ChecksPlanLimitOnCreate;
use App\Filament\Resources\Users\UserResource;
use App\Support\Auth\UserEmail;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    use ChecksPlanLimitOnCreate;

    protected static PlanLimit $planLimit = PlanLimit::Technicians;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        $data['company_id'] = $user->isSuperAdmin()
            ? session('selected_company_id')
            : $user->company_id;

        return $data;
    }

    /** Dos altas simultáneas con el mismo correo: la segunda ve el error en el campo, no un error 500. */
    protected function handleRecordCreation(array $data): Model
    {
        return UserEmail::guard(fn () => parent::handleRecordCreation($data), 'data.email');
    }

    /** El técnico recibe el enlace para confirmar su correo (no lo bloquea: puede trabajar igual). */
    protected function afterCreate(): void
    {
        $this->record->sendVerificationSafely();
    }
}
