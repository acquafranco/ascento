<?php

namespace App\Notifications\App;

use App\Notifications\AppNotification;

/** Pedido comercial nuevo desde la calculadora (campanita de los SuperAdmin). */
class SalesLeadNotification extends AppNotification
{
    public function __construct(public int $leadId, public string $type, public string $who) {}

    public function title(): string
    {
        return 'Pedido comercial: '.$this->type;
    }

    public function body(): string
    {
        return $this->who.' lo pidió desde la calculadora.';
    }

    public function path(): ?string
    {
        return '/admin/pedidos-comerciales/'.$this->leadId;
    }

    public function dedupeKey(): ?string
    {
        return 'sales-lead:'.$this->leadId;
    }

    public function icon(): string
    {
        return 'heroicon-o-calculator';
    }
}
