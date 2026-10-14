<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Aviso al correo ACTUAL de que se pidió cambiarlo (por si no fue la persona). */
class EmailChangeRequestedNotification extends Notification
{
    public function __construct(public readonly string $newEmail) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Se pidió cambiar el correo de tu cuenta - Ascento')
            ->greeting('Pedido de cambio de correo')
            ->line('Se pidió cambiar el correo de tu cuenta de Ascento a '.$this->newEmail.'.')
            ->line('El cambio se hace recién cuando se confirme desde esa dirección. Hasta entonces seguís entrando con este correo.')
            ->line('Si no fuiste vos, entrá a Ascento, cancelá el cambio desde tu perfil y cambiá tu contraseña.')
            ->salutation('Ascento');
    }
}
