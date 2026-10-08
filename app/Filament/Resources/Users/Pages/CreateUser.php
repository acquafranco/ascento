<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Auth;

class CreateUser extends CreateRecord
{
    use \App\Filament\Concerns\ChecksPlanLimitOnCreate;

    protected static \App\Enums\PlanLimit $planLimit = \App\Enums\PlanLimit::Technicians;

    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $user = Auth::user();

        $data['company_id'] = $user->isSuperAdmin()
            ? session('selected_company_id')
            : $user->company_id;

        return $data;
    }
}
