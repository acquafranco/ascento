<?php

namespace App\Filament\Pages;

use App\Models\Company;
use Filament\Pages\Page;

class CompanyOverview extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Empresa';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.company-overview';

    public Company $company;

    public function mount(Company $company)
    {
        abort_unless(auth()->user()->isSuperAdmin(), 403);

        $this->company = $company;
    }

    public static function canAccess(): bool
    {
        return auth()->check()
            && auth()->user()->isSuperAdmin();
    }

    public function getStats(): array
    {
        // Sin el filtro por empresa elegida (BelongsToCompany): la relación ya
        // limita a esta empresa, y con el filtro daba 0 si se había elegido otra.
        return [
            'Edificios' => $this->company->buildings()->withoutGlobalScope('company')->count(),
            'Usuarios' => $this->company->users()->count(),
            'Remitos' => $this->company->deliveryNotes()->withoutGlobalScope('company')->count(),
            'Órdenes' => $this->company->workOrders()->withoutGlobalScope('company')->count(),
        ];
    }
}
