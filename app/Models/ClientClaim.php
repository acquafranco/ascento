<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reclamo de un cliente desde el portal. Se crea y se cambia solo desde
 * ClientClaimService (empresa, cliente y edificio salen de relaciones reales).
 */
class ClientClaim extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = [
        'stopped' => 'Ascensor detenido',
        'doors' => 'Puertas con problemas',
        'noise' => 'Ruidos o movimientos inusuales',
        'malfunction' => 'Problemas de funcionamiento',
        'request' => 'Consulta o pedido de mantenimiento',
        'other' => 'Otro',
    ];

    public const NEW = 'new';

    public const STATUSES = [
        'new' => 'Nuevo',
        'reviewing' => 'En revisión',
        'in_progress' => 'En gestión',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
    ];

    public const OPEN_STATUSES = ['new', 'reviewing', 'in_progress'];

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'number' => 'integer',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withoutGlobalScopes();
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(Building::class)->withoutGlobalScopes()->withTrashed();
    }

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class)->withoutGlobalScopes();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withoutGlobalScopes()->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to')->withoutGlobalScopes()->withTrashed();
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class)->withoutGlobalScopes();
    }

    public function events(): HasMany
    {
        return $this->hasMany(ClientClaimEvent::class)->orderBy('created_at')->orderBy('id');
    }

    public function publicEvents(): HasMany
    {
        return $this->events()->where('public', true);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ClientClaimPhoto::class);
    }

    public function reference(): string
    {
        return 'R-'.str_pad((string) $this->number, 5, '0', STR_PAD_LEFT);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function placeLabel(): string
    {
        return trim(($this->building?->name ?? 'Edificio').($this->elevator ? ' · '.$this->elevator->label : ''));
    }
}
