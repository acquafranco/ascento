<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Sección de fotos de mantenimientos e inspecciones (Pozo, Techo, Sala de
 * máquinas, Cabina…). Cada empresa administra las suyas desde el panel. No
 * se borran: se desactivan, y las fotos históricas conservan su sección.
 */
class PhotoSection extends Model
{
    use BelongsToCompany;

    public const DEFAULTS = ['Pozo', 'Techo', 'Sala de máquinas'];

    protected $fillable = ['name', 'position', 'is_active'];

    protected $casts = [
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (PhotoSection $section) {
            $section->name = trim(preg_replace('/\s+/u', ' ', (string) $section->name));
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function photos()
    {
        return $this->hasMany(VisitPhoto::class);
    }

    /**
     * Secciones activas de una empresa, en orden. La primera vez se crean
     * las iniciales (una sola vez: si la empresa ya tiene secciones, aunque
     * estén todas desactivadas, no se vuelven a crear).
     *
     * @return Collection<int, PhotoSection>
     */
    public static function forCompany(int $companyId): Collection
    {
        if (! static::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            static::ensureDefaults($companyId);
        }

        return static::withoutGlobalScopes()->where('company_id', $companyId)->active()
            ->orderBy('position')->orderBy('id')->get(['id', 'company_id', 'name', 'position']);
    }

    public static function ensureDefaults(int $companyId): void
    {
        // insertOrIgnore + único (empresa, nombre): dos pedidos simultáneos no duplican.
        static::withoutGlobalScopes()->insertOrIgnore(collect(self::DEFAULTS)->map(fn (string $name, int $i) => [
            'company_id' => $companyId,
            'name' => $name,
            'position' => $i + 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ])->all());
    }
}
