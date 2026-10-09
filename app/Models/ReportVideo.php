<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Video de un reporte (uno por reporte). Disco privado: solo se sirve por
 * ReportVideoController (panel / app del técnico) o por el portal si el
 * reporte se compartió.
 */
class ReportVideo extends Model
{
    use BelongsToCompany;

    public const PENDING = 'pending';

    public const PROCESSING = 'processing';

    public const READY = 'ready';

    /** MIME reales aceptados (detectados por contenido, no por la extensión). */
    public const MIMES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm'];

    protected $fillable = [];

    protected $casts = ['processed_at' => 'datetime', 'size' => 'integer', 'duration' => 'integer'];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class)->withoutGlobalScopes();
    }

    /** Solo rutas dentro de la carpeta de videos de su empresa. */
    public function hasSafePath(): bool
    {
        return str_starts_with((string) $this->path, 'reports/'.$this->company_id.'/videos/') && ! str_contains((string) $this->path, '..');
    }

    public function fullPath(): ?string
    {
        return $this->hasSafePath() && Storage::disk('local')->exists($this->path) ? Storage::disk('local')->path($this->path) : null;
    }

    /** ¿Lo reproduce cualquier navegador? (MOV de iPhone puede venir en HEVC). */
    public function isWebPlayable(): bool
    {
        return in_array($this->mime, ['video/mp4', 'video/webm'], true);
    }

    public function deleteFile(): void
    {
        if ($this->hasSafePath()) {
            Storage::disk('local')->delete($this->path);
        }
    }

    public function sizeLabel(): string
    {
        return number_format($this->size / 1048576, 1, ',', '.').' MB';
    }
}
