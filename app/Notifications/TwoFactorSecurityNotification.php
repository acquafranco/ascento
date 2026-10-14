<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisos de seguridad del segundo factor al dueño de la cuenta. Nunca llevan
 * secretos, códigos ni datos que ayuden a un atacante.
 */
class TwoFactorSecurityNotification extends Notification
{
    public const ACTIVATED = 'activated';

    public const RECOVERY_USED = 'recovery_used';

    public const CODES_REGENERATED = 'codes_regenerated';

    public const RESET = 'reset';

    public const FAILED_CODES = 'failed_codes';

    public function __construct(public readonly string $event, public readonly array $data = []) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        [$subject, $lines] = match ($this->event) {
            self::ACTIVATED => ['Activaste el segundo factor', ['El segundo factor quedó activo en tu cuenta de Ascento. Se cerraron tus otras sesiones.']],
            self::RECOVERY_USED => ['Se usó un código de recuperación', ['Se entró a tu cuenta con un código de recuperación. Te quedan '.(int) ($this->data['remaining'] ?? 0).'.', 'Si te quedan pocos, generá nuevos desde «Segundo factor».']],
            self::CODES_REGENERATED => ['Generaste códigos de recuperación nuevos', ['Los códigos anteriores ya no sirven.']],
            self::RESET => ['Se restableció el segundo factor de tu cuenta', ['El segundo factor de tu cuenta se quitó y se cerraron todas tus sesiones. La próxima vez que entres vas a tener que configurarlo de nuevo.']],
            self::FAILED_CODES => ['Varios códigos incorrectos en tu cuenta', ['Hubo varios intentos con códigos incorrectos después de poner tu contraseña.', 'Si no fuiste vos, alguien conoce tu contraseña: cambiala.']],
            default => ['Aviso de seguridad', ['Hubo un cambio de seguridad en tu cuenta.']],
        };

        $mail = (new MailMessage)->subject($subject.' - Ascento')->greeting($subject);
        foreach ($lines as $line) {
            $mail->line($line);
        }

        return $mail->line('Si no fuiste vos, avisá enseguida al responsable de Ascento.')->salutation('Ascento');
    }
}
