<?php

namespace App\Filament\Resources\Companies\Pages;

use App\Filament\Resources\Companies\CompanyDeletion;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Company;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\RateLimiter;

/** Ficha de una empresa (solo SuperAdmin). */
class ViewCompany extends ViewRecord
{
    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enter')
                ->label('Entrar a la empresa')
                ->icon('heroicon-o-arrow-right')
                ->action(function () {
                    session(['selected_company_id' => $this->record->id]);

                    return redirect()->to('/admin');
                }),
            Action::make('resendVerification')
                ->label('Reenviar confirmación de correo')
                ->icon('heroicon-o-envelope')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(fn () => 'Se le envía de nuevo el enlace de confirmación a '.$this->record->owner?->email.'.')
                ->visible(fn () => $this->record->owner && ! $this->record->owner->hasVerifiedEmail())
                ->action(function () {
                    $owner = $this->record->owner;
                    $key = 'superadmin-resend-verification:'.$owner->id;
                    if (RateLimiter::tooManyAttempts($key, 3)) {
                        Notification::make()->title('Ya se reenvió varias veces')->body('Esperá unos minutos antes de volver a intentarlo.')->warning()->send();

                        return;
                    }
                    RateLimiter::hit($key, 600);
                    $owner->sendEmailVerificationNotification();
                    Notification::make()->title('Correo de confirmación reenviado')->success()->send();
                }),
            Action::make('subscription')
                ->label('Suscripción')
                ->icon('heroicon-o-credit-card')
                ->color('gray')
                ->visible(fn () => $this->record->latestSubscription !== null)
                ->url(fn () => SubscriptionResource::getUrl('view', ['record' => $this->record->latestSubscription])),
            EditAction::make(),
            CompanyDeletion::action(),
        ];
    }

    public function getTitle(): string
    {
        /** @var Company $company */
        $company = $this->record;

        return $company->name;
    }
}
