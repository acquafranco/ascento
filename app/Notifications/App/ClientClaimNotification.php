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
        return 'Ver el reclamo';
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
        return $this->kind === 'created' ? $this->mail($this->title(), [
            'Un cliente registró un reclamo desde el portal.',
            'Lugar: '.$this->place.'.',
            'Categoría: '.$this->category.'.',
            'Abrilo en Ascento para ver el detalle.',
        ]) : null;
    }
}
