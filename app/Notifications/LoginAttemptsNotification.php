<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Etapa 11 (R6): avisos por intentos de ingreso fallidos. No dicen desde
 * dónde ni cuántos exactamente al dueño de la cuenta: nada útil para quien ataca.
 */
class LoginAttemptsNotification extends Notification
{
    public const ACCOUNT = 'account';

    public const SPIKE = 'spike';

    public function __construct(public readonly string $type, public readonly array $data = []) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if ($this->type === self::SPIKE) {
            return (new MailMessage)
                ->subject('Muchos ingresos fallidos en Ascento')
                ->greeting('Muchos ingresos fallidos')
                ->line('En la última hora hubo '.(int) ($this->data['count'] ?? 0).' o más intentos de ingreso fallidos en la plataforma.')
                ->line('Los límites automáticos ya los están frenando. Revisá el log del servidor si sigue.')
                ->salutation('Ascento');
        }

        return (new MailMessage)
            ->subject('Intentos de entrar a tu cuenta - Ascento')
            ->greeting('Hubo varios intentos de entrar a tu cuenta')
            ->line('Se probaron contraseñas incorrectas varias veces para tu cuenta de Ascento.')
            ->line('Si fuiste vos, podés ignorar este aviso. Si no, cambiá tu contraseña desde «¿Olvidaste tu contraseña?».')
            ->line('Tu cuenta no se bloqueó: podés seguir entrando con normalidad.')
            ->salutation('Ascento');
    }
}
