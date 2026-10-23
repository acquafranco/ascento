<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\Users\Widgets\UserOrdersWidget;
use App\Filament\Resources\Users\Widgets\UserStatsWidget;
use App\Models\User;
use App\Services\Interventions\UrgentCoverage;
use App\Services\Technicians\TechnicianSheet;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            UserStatsWidget::class,
            UserOrdersWidget::class,
        ];
    }

    /** Estado de la autorización de urgencias (para el subtítulo de la ficha). */
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        if (! $record instanceof User || $record->role !== 'technician') {
            return null;
        }

        return $record->hasUrgentCoverage()
            ? 'Puede registrar urgencias en cualquier edificio de la empresa · autorizado por '.($record->urgentCoverageGrantedBy?->name ?? '—').' el '.$record->urgent_coverage_granted_at->timezone(config('app.timezone'))->format('d/m/Y H:i')
            : null;
    }

    /**
     * La planilla del técnico (solo el dueño: TechnicianSheet::canView) y la
     * autorización de urgencias en cualquier edificio (UrgentCoverage: dueño o
     * administrativo que gestiona Guardias). Solo sirve para registrar
     * intervenciones; no amplía ningún otro permiso.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sheet')
                ->label('Ver planilla')
                ->icon('heroicon-o-table-cells')
                ->url(fn ($record) => TechnicianSheetPage::getUrl(['record' => $record]))
                ->visible(fn ($record) => TechnicianSheet::canView(auth()->user(), $record)),
            Action::make('grantUrgentCoverage')
                ->label('Autorizar urgencias en cualquier edificio')
                ->icon('heroicon-o-bolt')
                ->color('gray')
                // Autorización propia (no la de editar el usuario, que es solo del dueño): UrgentCoverage.
                ->authorize(fn (User $record) => UrgentCoverage::canManage(auth()->user(), $record))
                ->visible(fn (User $record) => ! $record->hasUrgentCoverage() && UrgentCoverage::canManage(auth()->user(), $record))
                ->requiresConfirmation()
                ->modalHeading('Autorizar urgencias en cualquier edificio')
                ->modalDescription('Podrá registrar intervenciones (urgencias y trabajos no programados) en cualquier edificio de la empresa, aunque no lo tenga asignado ni esté de guardia. No cambia sus mantenimientos, inspecciones, edificios asignados ni lo que ve en la app. Queda registrado quién lo autorizó y cuándo.')
                ->modalSubmitActionLabel('Autorizar')
                ->action(function (User $record) {
                    app(UrgentCoverage::class)->grant(auth()->user(), $record);
                    Notification::make()->title('Autorización otorgada')->success()->send();
                }),
            Action::make('revokeUrgentCoverage')
                ->label('Revocar urgencias en cualquier edificio')
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->authorize(fn (User $record) => UrgentCoverage::canManage(auth()->user(), $record))
                ->visible(fn (User $record) => $record->hasUrgentCoverage() && UrgentCoverage::canManage(auth()->user(), $record))
                ->requiresConfirmation()
                ->modalHeading('Revocar la autorización de urgencias')
                ->modalDescription('Desde ahora solo podrá registrar intervenciones en sus edificios asignados o mientras esté de guardia. Las intervenciones que ya registró no cambian.')
                ->modalSubmitActionLabel('Revocar')
                ->action(function (User $record) {
                    app(UrgentCoverage::class)->revoke(auth()->user(), $record);
                    Notification::make()->title('Autorización revocada')->success()->send();
                }),
        ];
    }
}
