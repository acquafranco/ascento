<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo a una persona que YA usa el portal: otra empresa (u otro cliente) le
 * dio acceso. Ingresa con su cuenta de siempre; no lleva enlaces de acceso ni
 * información de otras empresas.
 */
class PortalAccessGrantedNotification extends Notification
{
    public function __construct(private string $companyName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->companyName.' te dio acceso a su portal de clientes - Ascento')
            ->greeting('Hola '.$notifiable->name)
            ->line($this->companyName.' te dio acceso a su portal de clientes en Ascento.')
            ->line('Ingresá con tu email y tu contraseña de siempre. Si trabajás con más de una empresa, elegí cuál ver desde el portal.')
            ->action('Ingresar al portal', route('portal.login'))
            ->line('Si no esperabas este correo, podés ignorarlo.')
            ->salutation('Ascento');
    }
}
