<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ítem de un presupuesto. El subtotal y el total del presupuesto los calcula
 * SIEMPRE el servidor (cantidad × precio): nunca se toman del navegador.
 */
class QuoteItem extends Model
{
    use BelongsToCompany;

    protected $fillable = ['quote_id', 'position', 'concept', 'description', 'quantity', 'unit_price'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuoteItem $item) {
            $quote = Quote::withoutGlobalScopes()->withTrashed()->find($item->quote_id);

            abort_unless($quote, 422);

            // Mismo presupuesto, misma empresa (nunca la del request).
            $item->company_id = $quote->company_id;
            $item->quantity = round(max(0, (float) $item->quantity), 2);
            $item->unit_price = round(max(0, (float) $item->unit_price), 2);
            $item->subtotal = round((float) $item->quantity * (float) $item->unit_price, 2);
        });

        static::saved(fn (QuoteItem $item) => $item->quote?->refreshTotal());
        static::deleted(fn (QuoteItem $item) => $item->quote?->refreshTotal());
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class)->withTrashed()->withoutGlobalScopes();
    }
}
