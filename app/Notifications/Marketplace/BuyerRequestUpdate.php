<?php

namespace App\Notifications\Marketplace;

use App\Notifications\AppNotification;

/** Aviso a quien hizo un pedido de repuestos (campanita del panel). */
class BuyerRequestUpdate extends AppNotification
{
    public function __construct(public int $requestId, public string $reference, public string $heading, public string $text, public string $event) {}

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
        return '/admin/pedidos-de-repuestos?pedido='.$this->requestId;
    }

    public function dedupeKey(): ?string
    {
        return 'mkt-buyer:'.$this->requestId.':'.$this->event;
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }
}
