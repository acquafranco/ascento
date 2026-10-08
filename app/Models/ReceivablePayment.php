<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pago (total o parcial) de una obligación. Lo crea ReceivableService. */
class ReceivablePayment extends Model
{
    use BelongsToCompany;

    public const METHODS = [
        'cash' => 'Efectivo',
        'transfer' => 'Transferencia',
        'check' => 'Cheque',
        'other' => 'Otro',
    ];

    protected $fillable = [
        'company_id',
        'receivable_id',
        'amount',
        'paid_at',
        'method',
        'notes',
        'user_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function receivable(): BelongsTo
    {
        return $this->belongsTo(Receivable::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
