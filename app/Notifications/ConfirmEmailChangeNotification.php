<?php

namespace App\Notifications;

use App\Services\Auth\PendingEmailChange;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Enlace para confirmar el correo nuevo (se manda a ESA dirección). */
class ConfirmEmailChangeNotification extends Notification
{
    public function __construct(public readonly string $url, public readonly string $name) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmá tu correo nuevo - Ascento')
            ->greeting('Confirmá tu correo nuevo')
            ->line('Pediste usar esta dirección en tu cuenta de Ascento ('.$this->name.').')
            ->action('Confirmar este correo', $this->url)
            ->line('El enlace vence en '.PendingEmailChange::EXPIRES_MINUTES.' minutos y se puede usar una sola vez. Hasta que lo confirmes, seguís entrando con tu correo anterior.')
            ->line('Si no lo pediste, ignorá este correo: no cambia nada.')
            ->salutation('Ascento');
    }
}
