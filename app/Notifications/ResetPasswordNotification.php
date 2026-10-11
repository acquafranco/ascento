<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        protected string $token
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = url(route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], false));

        // Mismo diseño que el resto de los correos (tema "ascento").
        return (new MailMessage)
            ->subject('Restablecé tu contraseña - Ascento')
            ->greeting('Restablecé tu contraseña')
            ->line('Recibimos un pedido para restablecer la contraseña de tu cuenta en Ascento.')
            ->action('Elegir una contraseña nueva', $url)
            ->line('El enlace vence en '.config('auth.passwords.users.expire').' minutos y se puede usar una sola vez.')
            ->line('Si no lo pediste, ignorá este correo: tu contraseña actual no cambia.')
            ->salutation('Ascento');
    }
}
