<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reclamo o reporte de un problema. Dos orígenes, el mismo seguimiento:
 * - portal: lo presenta una persona del portal de la administración;
 * - qr: lo presenta cualquier persona desde el QR del edificio, sin cuenta
 *   (Ascento Conecta). La administración lo ve solo si la empresa lo comparte.
 *
 * Los estados describen el seguimiento ADMINISTRATIVO de la empresa: no
 * confirman una reparación ni que el equipo sea seguro. Se crea y se cambia
 * solo desde ClientClaimService (empresa, cliente y edificio salen de
 * relaciones reales, nunca del navegador).
 */
class ClientClaim extends Model
{
    use BelongsToCompany;

    public const ORIGIN_PORTAL = 'portal';

    public const ORIGIN_QR = 'qr';

    public const CATEGORIES = [
        'stopped' => 'Ascensor detenido',
        'doors' => 'Puertas con problemas',
        'noise' => 'Ruidos o movimientos inusuales',
        'malfunction' => 'Problemas de funcionamiento',
        'request' => 'Consulta o pedido de mantenimiento',
        // Reportes desde el QR (observaciones de quien usa el ascensor, no diagnósticos).
        'irregular_stop' => 'Se detiene de forma irregular',
        'lighting' => 'Problemas de iluminación',
        'damage' => 'Daños visibles en el interior',
        'other' => 'Otro',
    ];

    /** Lo que elige quien reporta desde el QR, en sus palabras. */
    public const QR_CATEGORIES = [
        'doors' => 'Las puertas no funcionan correctamente',
        'noise' => 'Ruidos o vibraciones inusuales',
        'irregular_stop' => 'El ascensor se detiene de forma irregular',
        'lighting' => 'Problemas de iluminación',
        'damage' => 'Daños visibles en el interior',
        'other' => 'Otro problema',
    ];

    public const NEW = 'new';

    public const LINKED = 'linked';

    public const STATUSES = [
        'new' => 'Recibido',
        'reviewing' => 'En revisión',
        'in_progress' => 'En gestión',
        'linked' => 'Asociado a una orden de trabajo',
        'resolved' => 'Resuelto',
        'closed' => 'Cerrado',
    ];

    /** Cerrar o dar por resuelto exige dejar el motivo (queda en el historial). */
    public const NEEDS_REASON = ['resolved', 'closed'];

    public const OPEN_STATUSES = ['new', 'reviewing', 'in_progress', 'linked'];

    /** Aclaración que acompaña los estados en el panel, el portal y el QR. */
    public const STATUS_NOTE = 'Los estados describen el seguimiento administrativo de la empresa: no confirman una reparación ni que el ascensor esté en condiciones de funcionamiento.';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'number' => 'integer',
            'shared_with_client' => 'boolean',
            'public_on_qr' => 'boolean',
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

    public function deliveries(): HasMany
    {
        return $this->hasMany(ClientClaimDelivery::class)->orderBy('id');
    }

    public function isFromQr(): bool
    {
        return $this->origin === self::ORIGIN_QR;
    }

    public function originLabel(): string
    {
        return $this->isFromQr() ? 'QR del edificio' : 'Portal';
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
