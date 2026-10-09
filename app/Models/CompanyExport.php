<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Exportación de datos de negocio de una empresa (no es un backup del
 * servidor). El archivo vive en el disco privado y expira; el registro queda
 * como historial.
 */
class CompanyExport extends Model
{
    use BelongsToCompany;

    public const REQUESTED = 'requested';

    public const GENERATING = 'generating';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const STATUSES = [
        self::REQUESTED => 'Solicitada',
        self::GENERATING => 'Generándose',
        self::COMPLETED => 'Completada',
        self::FAILED => 'Fallida',
    ];

    /** Días que el archivo queda disponible para descargar. */
    public const KEEP_DAYS = 7;

    /** Máximo de exportaciones por empresa por día (evita saturar el servidor). */
    public const DAILY_LIMIT = 5;

    protected $fillable = [];

    protected $casts = [
        'categories' => 'array',
        'warnings' => 'array',
        'size' => 'integer',
        'record_count' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'file_deleted_at' => 'datetime',
    ];

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by')->withTrashed();
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(CompanyExportDownload::class)->latest('downloaded_at');
    }

    public function isDownloadable(): bool
    {
        return $this->status === self::COMPLETED
            && $this->path !== null
            && $this->file_deleted_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && str_starts_with($this->path, 'exports/'.$this->company_id.'/')
            && ! str_contains($this->path, '..')
            && Storage::disk('local')->exists($this->path);
    }

    public function isPending(): bool
    {
        return in_array($this->status, [self::REQUESTED, self::GENERATING], true);
    }

    public function sizeLabel(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size / 1024)).' KB';
    }
}
