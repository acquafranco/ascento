<?php

namespace App\Support\Plans;

use App\Enums\PlanFeature;
use App\Enums\PlanLimit;
use App\Filament\Pages\Subscription;
use App\Models\Company;
use App\Support\CompanyContext;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Experiencia comercial cuando algo no entra en el plan: mensaje claro +
 * "Ver planes". Nunca un 403 ni un error genérico.
 */
class PlanUpsell
{
    public static function currentCompany(): ?Company
    {
        $id = CompanyContext::currentId();

        return $id ? Company::find($id) : null;
    }

    /** Pantalla "Mi suscripción" con los planes y el motivo. */
    public static function url(?PlanLimit $limit = null, ?PlanFeature $feature = null): string
    {
        return Subscription::getUrl(array_filter([
            'limite' => $limit?->value,
            'funcion' => $feature?->value,
        ]), panel: 'ascensores_app').'#planes';
    }

    public static function limitNotification(Company $company, PlanLimit $limit): Notification
    {
        $guard = PlanGuard::for($company);

        return Notification::make()
            ->title($guard->limitReachedMessage($limit))
            ->body($guard->upgradePitch($limit) ?? 'Actualizá tu plan para continuar.')
            ->warning()
            ->persistent()
            ->actions([
                Action::make('plans')->label('Ver planes')->button()->url(static::url($limit)),
            ]);
    }

    public static function featureNotification(Company $company, PlanFeature $feature): Notification
    {
        $guard = PlanGuard::for($company);

        return Notification::make()
            ->title($guard->featureUnavailableMessage($feature))
            ->body($guard->upgradePitch(feature: $feature))
            ->info()
            ->persistent()
            ->actions([
                Action::make('plans')->label('Ver planes')->button()->url(static::url(feature: $feature)),
            ]);
    }
}
