<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Solicitud de ayuda por posible atrapamiento, desde el QR público. Solo lo
 * necesario para actuar: edificio, ascensor (si se indicó), fecha y hora y el
 * código de la solicitud. El enlace lleva al acceso autorizado de cada
 * destinatario (panel, portal o app del técnico), que vuelve a comprobar
 * permisos: el aviso no da acceso a nada por sí solo.
 */
class EmergencyRequestNotification extends AppNotification
{
    public function __construct(
        public int $requestId,
        public string $reference,
        public string $place,
        public string $at,
        public int $round,
        public ?string $target,
        public int $building,
    ) {}

    public function title(): string
    {
        return $this->round > 1 ? '🚨 Siguen pidiendo ayuda: posible atrapamiento' : '🚨 Solicitud de ayuda: posible atrapamiento';
    }

    public function body(): string
    {
        return $this->place.' · '.$this->at.' · Solicitud '.$this->reference.'. Registrada desde el QR público del edificio; no está confirmada.';
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return 'emergency:'.$this->requestId.':'.$this->round;
    }

    public function buildingId(): ?int
    {
        return $this->building;
    }

    public function actionLabel(): string
    {
        return 'Ver la solicitud';
    }

    public function wantsPush(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'heroicon-o-exclamation-triangle';
    }

    public function color(): string
    {
        return 'danger';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->mail($this->title(), [
            'Se registró una solicitud de ayuda por posible atrapamiento desde el QR público del edificio.',
            'Lugar: '.$this->place.'.',
            'Fecha y hora: '.$this->at.'.',
            'Solicitud: '.$this->reference.'.',
            'Ascento no puede confirmar que haya alguien atrapado: verificalo y actuá según el procedimiento de emergencia del edificio.',
        ]);
    }
}
