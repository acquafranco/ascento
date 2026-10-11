<?php

namespace App\Notifications;

use App\Filament\Resources\Companies\CompanyResource;
use App\Models\Company;
use App\Models\User;
use App\Notifications\Concerns\SendsAdminPush;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al equipo de Ascento: se registró una empresa. El enlace lleva a la
 * ficha de la empresa en el panel (pide iniciar sesión como SuperAdmin);
 * nunca a algo que permita administrarla sin autenticación.
 */
class NewCompanyRegisteredNotification extends Notification
{
    use SendsAdminPush;

    public function __construct(public Company $company, public User $owner) {}

    private function url(): string
    {
        return CompanyResource::getUrl('view', ['record' => $this->company], panel: 'ascensores_app');
    }

    private function verifiedLabel(): string
    {
        return $this->owner->fresh()?->hasVerifiedEmail() ? 'Confirmado' : 'Pendiente de confirmar';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Nueva empresa en Ascento: '.$this->company->name)
            ->greeting('Se registró una empresa nueva')
            ->line('**Empresa:** '.$this->company->name)
            ->line('**Responsable:** '.$this->owner->name)
            ->line('**Correo de registro:** '.$this->owner->email)
            ->line('**Fecha y hora:** '.$this->company->created_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'))
            ->line('**Correo confirmado:** '.$this->verifiedLabel())
            ->line('**Prueba gratis hasta:** '.($this->company->trial_ends_at?->format('d/m/Y') ?? '—'))
            ->action('Ver la empresa en el panel', $this->url())
            ->line('El enlace pide iniciar sesión con tu usuario de SuperAdmin.')
            ->salutation('Ascento');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title('Nueva empresa: '.$this->company->name)
            ->body($this->owner->name.' · '.$this->owner->email)
            ->icon('heroicon-o-building-office-2')
            ->iconColor('success')
            ->actions([Action::make('view')->label('Ver empresa')->url($this->url())->markAsRead()])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Nueva empresa en Ascento')
            ->body($this->company->name.' · '.$this->owner->name)
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag('company-'.$this->company->id)
            ->data(['url' => parse_url($this->url(), PHP_URL_PATH)])
            ->options(['TTL' => 24 * 3600]);
    }

    /** @return array{text: string, button: array{0: string, 1: string}} */
    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => '<b>Nueva empresa en Ascento</b>'."\n".e($this->company->name)."\n".e($this->owner->name.' · '.$this->owner->email),
            'button' => ['Ver empresa', $this->url()],
        ];
    }
}
