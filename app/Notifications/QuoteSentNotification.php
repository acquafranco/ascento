<?php

namespace App\Notifications;

use App\Models\Company;
use App\Models\Quote;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Presupuesto enviado al cliente (correo con el diseño de Ascento y el PDF adjunto). */
class QuoteSentNotification extends Notification
{
    public function __construct(
        private Quote $quote,
        private Company $company,
        private string $url,
        private ?string $pdf,
        private ?string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $q = $this->quote;
        $mail = (new MailMessage)
            ->subject('Presupuesto '.$q->numberLabel().' de '.$this->company->name.' - '.$q->title)
            ->greeting('Hola'.($q->client?->name ? ' '.$q->client->name : ''))
            ->line($this->company->name.' te envió el presupuesto '.$q->numberLabel().'.');

        if (filled($this->message)) {
            $mail->line($this->message);
        }

        $mail->line('**Trabajo:** '.$q->title)
            ->line('**Edificio:** '.trim(($q->building?->name ?? '').' '.($q->building?->address ?? '')))
            ->line('**Total:** $ '.number_format((float) $q->amount, 2, ',', '.'));

        if ($q->valid_until) {
            $mail->line('**Válido hasta:** '.$q->valid_until->format('d/m/Y'));
        }

        $mail->action('Ver el presupuesto', $this->url)
            ->line('Por seguridad, el enlace vence. Si necesitás verlo más adelante, pedile a '.$this->company->name.' que te lo reenvíe.')
            ->salutation($this->company->name);

        if ($this->company->email) {
            $mail->replyTo($this->company->email, $this->company->name);
        }

        if ($this->pdf) {
            $mail->attachData($this->pdf, 'presupuesto-'.$q->numberLabel().'.pdf', ['mime' => 'application/pdf']);
        }

        return $mail;
    }
}
