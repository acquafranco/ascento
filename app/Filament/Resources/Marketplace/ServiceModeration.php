<?php

namespace App\Filament\Resources\Marketplace;

use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileReport;
use App\Services\Activity\PlatformAudit;
use App\Services\Marketplace\ServiceProfiles;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Acciones del SuperAdmin sobre perfiles profesionales y sus reportes.
 * Distingue aprobar, ocultar, suspender y reactivar (con motivo y auditoría);
 * no hay acción de borrado: los perfiles y sus solicitudes se conservan.
 */
class ServiceModeration
{
    /** @return list<Action> */
    public static function profileActions(): array
    {
        $reason = fn () => Textarea::make('reason')->label('Motivo (lo ve el titular del perfil)')->rows(2)->maxLength(500)->required();

        return [
            Action::make('approve')->label('Aprobar')->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (ServiceProfile $record) => MarketplaceModeration::allowed() && $record->approved_at === null && $record->moderation === null)
                ->requiresConfirmation()->modalDescription('Revisaste que la información sea profesional y no tenga datos personales. Aprobar no certifica títulos ni matrículas.')
                ->action(function (ServiceProfile $record) {
                    app(ServiceProfiles::class)->approve(auth()->user(), $record);
                    Notification::make()->title('Perfil aprobado')->success()->send();
                }),
            Action::make('hide')->label('Ocultar')->icon('heroicon-o-eye-slash')->color('warning')
                ->visible(fn (ServiceProfile $record) => MarketplaceModeration::allowed() && $record->moderation === null)
                ->modalDescription('Sale del catálogo; el titular puede corregirlo. Las solicitudes se conservan.')
                ->schema([$reason()])
                ->action(function (ServiceProfile $record, array $data) {
                    app(ServiceProfiles::class)->moderate(auth()->user(), $record, ServiceProfile::HIDDEN, $data['reason']);
                    Notification::make()->title('Perfil oculto')->success()->send();
                }),
            Action::make('suspend')->label('Suspender')->icon('heroicon-o-no-symbol')->color('danger')
                ->visible(fn (ServiceProfile $record) => MarketplaceModeration::allowed() && $record->moderation !== ServiceProfile::SUSPENDED)
                ->modalDescription('Sale del catálogo, no recibe solicitudes nuevas y el titular no puede editarlo ni responder. Nada se borra.')
                ->schema([$reason()])
                ->action(function (ServiceProfile $record, array $data) {
                    app(ServiceProfiles::class)->moderate(auth()->user(), $record, ServiceProfile::SUSPENDED, $data['reason']);
                    Notification::make()->title('Perfil suspendido')->success()->send();
                }),
            Action::make('restore')->label('Reactivar')->icon('heroicon-o-arrow-path')->color('success')
                ->visible(fn (ServiceProfile $record) => MarketplaceModeration::allowed() && $record->moderation !== null)
                ->requiresConfirmation()
                ->action(function (ServiceProfile $record) {
                    app(ServiceProfiles::class)->restore(auth()->user(), $record);
                    Notification::make()->title('Perfil reactivado')->success()->send();
                }),
        ];
    }

    /** @return list<Action> */
    public static function reportActions(): array
    {
        $close = function (ServiceProfileReport $record, string $status, array $data) {
            abort_unless(MarketplaceModeration::allowed() && $record->status === 'open', 403);
            $moderation = $data['moderation'] ?? null;
            if ($status === 'resolved' && in_array($moderation, [ServiceProfile::HIDDEN, ServiceProfile::SUSPENDED], true) && $record->profile) {
                app(ServiceProfiles::class)->moderate(auth()->user(), $record->profile, $moderation, (string) $data['resolution']);
            }
            $record->forceFill(['status' => $status, 'resolution' => trim((string) ($data['resolution'] ?? '')) ?: null, 'resolved_by' => auth()->id(), 'resolved_at' => now()])->save();
            PlatformAudit::record('marketplace.service_report.'.$status, $record, ['moderation' => $moderation], 'Reporte de perfil #'.$record->id);
            Notification::make()->title($status === 'resolved' ? 'Reporte resuelto' : 'Reporte descartado')->success()->send();
        };

        return [
            Action::make('resolve')->label('Resolver')->icon('heroicon-o-check')->color('success')
                ->visible(fn (ServiceProfileReport $record) => MarketplaceModeration::allowed() && $record->status === 'open')
                ->schema([
                    Textarea::make('resolution')->label('Qué se hizo (si se oculta o suspende, es el motivo que ve el titular)')->rows(2)->maxLength(500)->required(),
                    Select::make('moderation')->label('Además')->options(['' => 'No cambiar el perfil', ServiceProfile::HIDDEN => 'Ocultar el perfil', ServiceProfile::SUSPENDED => 'Suspender el perfil'])->default('')->native(false),
                ])
                ->action(fn (ServiceProfileReport $record, array $data) => $close($record, 'resolved', $data)),
            Action::make('dismiss')->label('Descartar')->icon('heroicon-o-x-mark')->color('gray')
                ->visible(fn (ServiceProfileReport $record) => MarketplaceModeration::allowed() && $record->status === 'open')
                ->schema([Textarea::make('resolution')->label('Por qué se descarta')->rows(2)->maxLength(500)])
                ->action(fn (ServiceProfileReport $record, array $data) => $close($record, 'dismissed', $data)),
        ];
    }
}
