<?php

namespace App\Filament\Pages;

use App\Models\CompanyExport;
use App\Services\Exports\CompanyExportService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * "Exportar datos": el admin descarga una copia de los datos de negocio de
 * su empresa (ZIP con Excel + adjuntos) y ve el historial.
 */
class CompanyExports extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-down-tray';

    protected static ?string $navigationLabel = 'Exportar datos';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'exportaciones';

    protected static ?string $title = 'Exportar datos de la empresa';

    protected string $view = 'filament.pages.company-exports';

    public static function canAccess(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->isAdmin() && ! $user->isSuperAdmin() && $user->company_id !== null;
    }

    public function getSubheading(): ?string
    {
        return 'Descargá una copia de los datos de tu empresa para tenerlos y abrirlos con Excel.';
    }

    public function requestExport(): void
    {
        try {
            app(CompanyExportService::class)->request(Auth::user());

            Notification::make()->title('Exportación solicitada')->body('Se prepara en uno o dos minutos. Esta página se actualiza sola.')->success()->send();
        } catch (ValidationException $e) {
            Notification::make()->title(collect($e->errors())->flatten()->first())->warning()->send();
        }
    }

    protected function getViewData(): array
    {
        $exports = CompanyExport::query()->with(['requester:id,name', 'downloads.user:id,name'])->latest('id')->limit(30)->get();

        return [
            'exports' => $exports,
            'pending' => $exports->contains(fn (CompanyExport $e) => $e->isPending()),
        ];
    }
}
