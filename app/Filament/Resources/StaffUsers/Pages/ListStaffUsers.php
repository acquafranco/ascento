<?php

namespace App\Filament\Resources\StaffUsers\Pages;

use App\Enums\PlanLimit;
use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Models\Company;
use App\Support\Plans\PlanGuard;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListStaffUsers extends ListRecords
{
    protected static string $resource = StaffUserResource::class;

    /** Cupo del plan: «1 de 1 administrativos (plan Profesional)». */
    public function getSubheading(): ?string
    {
        $company = Company::find(auth()->user()->company_id);
        $guard = PlanGuard::for($company);
        $max = $guard->limit(PlanLimit::AdminUsers);
        $plan = $company->plan()->shortName();

        return $max === null
            ? "Administrativos sin límite (plan {$plan})."
            : "{$guard->usage(PlanLimit::AdminUsers)} de {$max} administrativos activos (plan {$plan}). El dueño no cuenta.";
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo administrativo'),
        ];
    }
}
