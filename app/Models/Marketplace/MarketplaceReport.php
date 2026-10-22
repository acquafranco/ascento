<?php

namespace App\Models\Marketplace;

use App\Models\Company;
use App\Support\Marketplace\Catalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Reporte de una publicación hecho por un comprador; lo resuelve el SuperAdmin. */
class MarketplaceReport extends Model
{
    public const STATUSES = ['open' => 'Abierto', 'resolved' => 'Resuelto', 'dismissed' => 'Descartado'];

    protected $guarded = ['id'];

    protected $casts = ['resolved_at' => 'datetime'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(SupplierProduct::class, 'supplier_product_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function reasonLabel(): string
    {
        return Catalog::REPORT_REASONS[$this->reason] ?? $this->reason;
    }
}
