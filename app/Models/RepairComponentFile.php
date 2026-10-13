<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Documento o foto de un componente en reparación (Etapa 8.4). Disco
 * privado, carpeta de la empresa; solo por RepairComponentFileController
 * (área «Reparaciones»). Nunca en el QR ni en el portal.
 */
class RepairComponentFile extends Model
{
    use BelongsToCompany;

    protected $fillable = [];

    protected $casts = ['size' => 'integer'];

    public function component(): BelongsTo
    {
        return $this->belongsTo(RepairComponent::class, 'repair_component_id');
    }

    public function hasSafePath(): bool
    {
        return (string) $this->path !== ''
            && str_starts_with($this->path, 'repairs/'.$this->company_id.'/')
            && ! str_contains($this->path, '..');
    }

    public function url(): string
    {
        return route('repair-component-files.show', $this);
    }
}
