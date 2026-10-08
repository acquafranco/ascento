<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una cuota mensual cobrada (o intentada) por Mercado Pago.
 */
class SubscriptionPayment extends Model
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PENDING = 'pending';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    protected $fillable = [
        'subscription_id',
        'company_id',
        'provider',
        'provider_payment_id',
        'provider_preapproval_id',
        'mp_payment_id',
        'status',
        'status_detail',
        'amount',
        'currency',
        'paid_at',
        'period_start',
        'period_end',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
