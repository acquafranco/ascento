<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consentimiento y última posición de un técnico (una fila por técnico).
 * Se escribe solo desde TechnicianLocationService, con la identidad de la
 * sesión: nunca con ids enviados por el navegador.
 */
class TechnicianLocation extends Model
{
    use BelongsToCompany;

    public const ONLINE = 'online';

    public const STALE = 'stale';

    public const NO_LOCATION = 'no_location';

    public const DISABLED = 'disabled';

    public const PERMISSION_PENDING = 'permission_pending';

    public const PERMISSION_DENIED = 'permission_denied';

    public const ERROR = 'error';

    public const STATUS_LABELS = [
        self::ONLINE => 'En línea',
        self::STALE => 'Ubicación desactualizada',
        self::NO_LOCATION => 'Sin ubicación',
        self::DISABLED => 'Ubicación desactivada',
        self::PERMISSION_PENDING => 'Permiso pendiente',
        self::PERMISSION_DENIED => 'Permiso denegado',
        self::ERROR => 'Error de actualización',
    ];

    public const ERRORS = ['timeout', 'unavailable', 'insecure', 'unsupported'];

    protected $guarded = ['*'];

    protected $hidden = ['latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'tracking_enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
            'located_at' => 'datetime',
            'last_error_at' => 'datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasPosition(): bool
    {
        return $this->latitude !== null && $this->longitude !== null && $this->located_at !== null;
    }

    public function isFresh(): bool
    {
        return $this->hasPosition() && $this->located_at->gt(now()->subSeconds((int) config('location.fresh_seconds', 300)));
    }

    /** Estado para el administrador. Nunca presenta una posición vieja como actual. */
    public function status(): string
    {
        return match (true) {
            ! $this->tracking_enabled => self::DISABLED,
            $this->permission === 'denied' => self::PERMISSION_DENIED,
            $this->isFresh() => self::ONLINE,
            $this->last_error !== null && $this->last_error_at !== null && ($this->located_at === null || $this->last_error_at->gt($this->located_at)) => self::ERROR,
            $this->hasPosition() => self::STALE,
            $this->permission !== 'granted' => self::PERMISSION_PENDING,
            default => self::NO_LOCATION,
        };
    }
}
