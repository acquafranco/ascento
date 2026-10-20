<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Avisos de reclamos: a la empresa (reclamo nuevo, asignación) y al cliente
 * que lo presentó (cambio de estado o respuesta). Solo el código, el lugar y
 * la categoría: ni notas internas ni datos de contacto. El enlace vuelve a
 * verificar permisos al abrirse.
 */
class ClientClaimNotification extends AppNotification
{
    public function __construct(
        public string $kind,
        public int $claimId,
        public string $reference,
        public string $place,
        public string $category,
        public ?string $target,
        public int $building,
        public string $extra = '',
        public string $key = '',
    ) {}

    public function title(): string
    {
        return match ($this->kind) {
            'created' => 'Reclamo nuevo '.$this->reference,
            'qr_created' => 'Problema reportado desde el QR '.$this->reference,
            'qr_shared' => 'La empresa compartió el reporte '.$this->reference,
            'assigned' => 'Te asignaron el reclamo '.$this->reference,
            'status' => 'Tu reclamo '.$this->reference.' cambió de estado',
            default => 'Novedades en tu reclamo '.$this->reference,
        };
    }

    public function body(): string
    {
        return $this->place.' · '.$this->category.($this->extra !== '' ? ' · '.$this->extra : '');
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return 'claim:'.$this->claimId.':'.$this->kind.($this->key !== '' ? ':'.$this->key : '');
    }

    public function buildingId(): ?int
    {
        return $this->building;
    }

    public function actionLabel(): string
    {
        return str_starts_with($this->kind, 'qr_') ? 'Ver el reporte' : 'Ver el reclamo';
    }

    public function wantsPush(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'heroicon-o-chat-bubble-left-ellipsis';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return match ($this->kind) {
            'created' => $this->mail($this->title(), [
                'Un cliente registró un reclamo desde el portal.',
                'Lugar: '.$this->place.'.',
                'Categoría: '.$this->category.'.',
                'Abrilo en Ascento para ver el detalle.',
            ]),
            // Lo que reportó una persona desde el QR: el detalle se ve solo con sesión.
            'qr_created' => $this->mail($this->title(), array_values(array_filter([
                'Una persona reportó un problema desde el QR del edificio.',
                'Lugar: '.$this->place.'.',
                'Categoría: '.$this->category.'.',
                $this->extra !== '' ? 'Registrado: '.$this->extra.'.' : '',
                'Abrilo en Ascento para ver el detalle. Es un reporte de una persona sin cuenta: no es una solicitud de rescate.',
            ]))),
            default => null,
        };
    }
}
