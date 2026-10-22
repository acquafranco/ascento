<?php

namespace App\Notifications\Marketplace;

use App\Notifications\AppNotification;
use Illuminate\Notifications\Messages\MailMessage;

/** Aviso a un proveedor sobre un pedido de cotización (su área /proveedores y correo). */
class SupplierRequestUpdate extends AppNotification
{
    public function __construct(public string $reference, public string $heading, public string $text, public string $event) {}

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
        return '/proveedores/pedidos/'.$this->reference;
    }

    public function dedupeKey(): ?string
    {
        return 'mkt-supplier:'.$this->reference.':'.$this->event;
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public function toMail(object $notifiable): ?MailMessage
    {
        return $this->mail($this->heading, [$this->text, 'Entrá a Ascento para ver el detalle.'], 'Ver el pedido');
    }
}
