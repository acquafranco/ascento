<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento o foto de una prueba de paracaídas. Vive en el disco privado,
 * dentro de la carpeta de la empresa, y solo se sirve por
 * ParachuteTestFileController (área «Inspecciones y reportes»).
 */
class ParachuteTestFile extends Model
{
    use BelongsToCompany;

    protected $fillable = [];

    protected $casts = ['size' => 'integer'];

    public function test(): BelongsTo
    {
        return $this->belongsTo(ParachuteTest::class, 'parachute_test_id');
    }

    public function hasSafePath(): bool
    {
        return (string) $this->path !== ''
            && str_starts_with($this->path, 'elevators/'.$this->company_id.'/parachute/')
            && ! str_contains($this->path, '..');
    }

    public function url(): string
    {
        return route('parachute-test-files.show', $this);
    }
}
