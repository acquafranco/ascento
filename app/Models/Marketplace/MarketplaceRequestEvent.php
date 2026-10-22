<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;

/** Historial de un pedido: quién lo movió, de qué estado a cuál y con qué nota. */
class MarketplaceRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function actorLabel(): string
    {
        return match ($this->actor) {
            'buyer' => 'Comprador',
            'supplier' => 'Proveedor',
            default => 'Ascento',
        };
    }
}
