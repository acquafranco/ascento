<?php

namespace App\Models\Marketplace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ítem de un pedido: copia del producto al pedirlo (si después cambia, el pedido no). */
class MarketplaceRequestItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'listed_price' => 'decimal:2',
        'quoted_unit_price' => 'decimal:2',
        'quantity' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(MarketplaceRequest::class, 'marketplace_request_id');
    }
}
