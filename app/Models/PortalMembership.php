<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Acceso de una persona al portal de UNA empresa, para UN cliente. Sin el
 * scope de empresa a propósito: la usan PortalAccess y el scope mismo para
 * resolver el contexto del cliente (filtrar siempre explícitamente).
 */
class PortalMembership extends Model
{
    protected $fillable = ['user_id', 'client_id'];

    protected $casts = [
        'invited_at' => 'datetime',
        'activated_at' => 'datetime',
        'deactivated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes()->withTrashed();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('deactivated_at');
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }

    /** Edificios de ESTE cliente autorizados para la persona. */
    public function buildingIds(): array
    {
        return Building::withoutGlobalScopes()
            ->where('company_id', $this->company_id)->where('client_id', $this->client_id)->whereNull('deleted_at')
            ->whereIn('id', fn ($q) => $q->select('building_id')->from('client_portal_buildings')->where('user_id', $this->user_id))
            ->pluck('id')->all();
    }
}
