<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Correo de invitación al portal. Lleva el enlace de activación (token de un
 * solo uso que vence en 72 h); nunca una contraseña. No se guarda como aviso
 * interno: el token no debe quedar en la base.
 */
class PortalInvitationNotification extends Notification
{
    public function __construct(private string $token, private string $companyName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('portal.invitation', ['token' => $this->token, 'email' => $notifiable->getEmailForPasswordReset()]);

        return (new MailMessage)
            ->subject($this->companyName.' te invitó a su portal de clientes - Ascento')
            ->greeting('Hola '.$notifiable->name)
            ->line($this->companyName.' te dio acceso a su portal de clientes en Ascento, donde vas a poder consultar los remitos, reportes, presupuestos y documentos que comparta de tus edificios.')
            ->line('Para activar tu cuenta, elegí tu contraseña desde este enlace:')
            ->action('Activar mi cuenta', $url)
            ->line('El enlace vence en 72 horas y se puede usar una sola vez. Si venció, pedile a '.$this->companyName.' que te reenvíe la invitación.')
            ->line('Si no esperabas este correo, podés ignorarlo.')
            ->salutation('Ascento');
    }
}
