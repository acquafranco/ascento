<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Foto de un reporte. El archivo vive en el disco privado y solo se sirve
 * por ReportPhotoController (con sesión y permisos). Al borrar la foto se
 * borra el archivo: no quedan huérfanos.
 */
class ReportPhoto extends Model
{
    use BelongsToCompany;

    protected $fillable = ['report_id', 'path', 'width', 'height', 'size', 'position'];

    protected $casts = [
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'position' => 'integer',
    ];

    protected static function booted(): void
    {
        // La foto es de la misma empresa que su reporte (nunca del request).
        static::creating(function (ReportPhoto $photo) {
            $report = Report::withoutGlobalScopes()->withTrashed()->find($photo->report_id);

            abort_unless($report, 422);

            $photo->company_id = $report->company_id;
        });

        static::deleted(fn (ReportPhoto $photo) => $photo->deleteFile());
    }

    public function report()
    {
        return $this->belongsTo(Report::class)->withTrashed();
    }

    /** Path seguro: dentro de la carpeta de la empresa y sin saltos de directorio. */
    public function hasSafePath(): bool
    {
        return $this->path !== ''
            && str_starts_with($this->path, 'reports/'.$this->company_id.'/')
            && ! str_contains($this->path, '..');
    }

    /** Disco donde está el archivo: privado; las fotos viejas, hasta moverlas, en el público. */
    public function disk(): ?string
    {
        if (! $this->hasSafePath()) {
            return null;
        }

        foreach (['local', 'public'] as $disk) {
            if (Storage::disk($disk)->exists($this->path)) {
                return $disk;
            }
        }

        return null;
    }

    /** Contenido para incrustar (PDF), o null si no está. */
    public function contents(): ?string
    {
        $disk = $this->disk();

        return $disk ? Storage::disk($disk)->get($this->path) : null;
    }

    public function url(): string
    {
        return route('reports.photos.show', ['report' => $this->report_id, 'photo' => $this->id]);
    }

    public function deleteFile(): void
    {
        if (! $this->hasSafePath()) {
            return;
        }

        // El mismo archivo puede seguir referenciado por la columna vieja
        // reports.photo (rollback): en ese caso se limpia también.
        Report::withoutGlobalScopes()->withTrashed()
            ->where('id', $this->report_id)
            ->where('photo', $this->path)
            ->update(['photo' => null]);

        Storage::disk('local')->delete($this->path);
        Storage::disk('public')->delete($this->path);
    }
}
