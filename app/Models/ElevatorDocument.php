<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Plano, manual, certificado o foto del legajo de un ascensor. El archivo
 * vive en el disco privado y solo se sirve por ElevatorDocumentController.
 */
class ElevatorDocument extends Model
{
    use \App\Models\Concerns\SharesWithClient;

    use BelongsToCompany;

    public const TYPES = [
        'certificate' => 'Certificado',
        'plan' => 'Plano',
        'manual' => 'Manual',
        'photo' => 'Foto',
        'other' => 'Otro',
    ];

    protected $fillable = ['elevator_id', 'type', 'title', 'expires_at'];

    protected $casts = [
        'expires_at' => 'date',
        'size' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (ElevatorDocument $document) {
            $elevator = Elevator::withoutGlobalScopes()->find($document->elevator_id);

            abort_unless($elevator, 422);

            $document->company_id = $elevator->company_id;
        });

        // Sin huérfanos: al borrar el documento se borra el archivo.
        static::deleted(function (ElevatorDocument $document) {
            if ($document->hasSafePath()) {
                Storage::disk('local')->delete($document->path);
            }
        });
    }

    public function elevator(): BelongsTo
    {
        return $this->belongsTo(Elevator::class);
    }

    public function hasSafePath(): bool
    {
        return (string) $this->path !== ''
            && str_starts_with($this->path, 'elevators/'.$this->company_id.'/')
            && ! str_contains($this->path, '..');
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    public function expiresSoon(int $days = 30): bool
    {
        return $this->expires_at !== null && ! $this->isExpired() && $this->expires_at->lte(today()->addDays($days));
    }

    public function url(): string
    {
        return route('elevator-documents.show', $this);
    }
}
