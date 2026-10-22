<?php

namespace App\Notifications\Marketplace;

use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Aviso del marketplace de repuestos. Lo entrega SIEMPRE MarketplaceNotifier
 * (a través de Notifier): en la app, en tiempo real, push si la persona lo
 * activó y correo solo en los eventos importantes ($withMail).
 *
 * - Para un proveedor: lleva $supplier (Notifier solo lo entrega a usuarios de ESE proveedor).
 * - Para el equipo de Ascento: $team = true (solo SuperAdmin).
 * - Para una empresa compradora: ni uno ni otro (Notifier verifica la empresa).
 *
 * El texto nunca incluye datos de otra empresa: a un proveedor, del comprador
 * solo su nombre; a un comprador, del proveedor solo su nombre comercial.
 */
class MarketplaceNotice extends AppNotification
{
    public function __construct(
        public string $heading,
        public string $text,
        public ?string $target,
        public string $key,
        public bool $withMail = false,
        public ?int $supplier = null,
        public bool $team = false,
        public bool $push = true,
    ) {}

    public function title(): string
    {
        return $this->heading;
    }

    public function body(): string
    {
        return $this->text;
    }

    public function path(): ?string
    {
        return $this->target;
    }

    public function dedupeKey(): ?string
    {
        return 'mkt:'.$this->key;
    }

    public function supplierId(): ?int
    {
        return $this->supplier;
    }

    public function forSuperAdmin(): bool
    {
        return $this->team;
    }

    public function wantsPush(): bool
    {
        return $this->push;
    }

    public function icon(): string
    {
        return 'heroicon-o-building-storefront';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->withMail ? $this->mail($this->heading, [$this->text, 'Entrá a Ascento para ver el detalle.'], 'Ver en Ascento') : null;
    }
}
