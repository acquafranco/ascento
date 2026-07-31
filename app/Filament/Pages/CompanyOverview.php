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
        return [
            'Edificios' => $this->company->buildings()->count(),
            'Usuarios' => $this->company->users()->count(),
            'Remitos' => $this->company->deliveryNotes()->count(),
            'Órdenes' => $this->company->workOrders()->count(),
        ];
    }
}
