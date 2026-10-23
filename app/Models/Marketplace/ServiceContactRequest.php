<?php

namespace App\Models\Marketplace;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Solicitud de contacto de una empresa de mantenimiento a un perfil
 * profesional. Conecta a las partes: no es un pedido, un contrato ni un pago.
 * Aparte de los pedidos de repuestos (MarketplaceRequest). Nunca se borra.
 */
class ServiceContactRequest extends Model
{
    public const OPEN = 'open';

    public const ANSWERED = 'answered';

    public const CLOSED = 'closed';

    public const STATUSES = [
        self::OPEN => 'Esperando respuesta',
        self::ANSWERED => 'Respondida',
        self::CLOSED => 'Cerrada',
    ];

    protected $guarded = ['id'];

    protected $casts = ['closed_at' => 'datetime', 'last_message_at' => 'datetime'];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ServiceProfile::class, 'service_profile_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ServiceContactMessage::class)->orderBy('id');
    }

    /** Las de una empresa (lo que envió). */
    public function scopeSentBy(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /** Las dirigidas a un perfil. */
    public function scopeForProfile(Builder $query, int $profileId): Builder
    {
        return $query->where('service_profile_id', $profileId);
    }

    public function isOpen(): bool
    {
        return $this->status !== self::CLOSED;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::OPEN => 'warn',
            self::ANSWERED => 'ok',
            default => '',
        };
    }

    public function buyerLabel(): string
    {
        return $this->buyer_name ?: 'Empresa dada de baja';
    }
}
