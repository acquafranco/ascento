<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Un correo ya armado (resúmenes), sin aviso interno propio. Lo usa Notifier. */
class MailOnlyNotification extends Notification
{
    public function __construct(private MailMessage $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return $this->message;
    }
}
