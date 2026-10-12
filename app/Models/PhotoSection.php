<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\PhotoZones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Zona de fotos de antes y después (Pozo, Sala de máquinas, Techo de
 * cabina…). Las define el sistema (App\Support\PhotoZones): cada empresa
 * tiene una fila por zona, creada sola la primera vez que se usa, y nadie
 * las administra. Las secciones propias de antes (sin código) no se
 * ofrecen más, pero no se borran: sus fotos conservan su sección y nombre.
 */
class PhotoSection extends Model
{
    use BelongsToCompany;

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

    /** Nombre de la zona del sistema (o el propio, para una sección vieja). */
    public function label(): string
    {
        return PhotoZones::LABELS[$this->code] ?? $this->name;
    }

    /**
     * Zonas que se pueden fotografiar en ese tipo de trabajo, en el orden del
     * sistema (cada una con su fila de la empresa, que se crea si falta).
     * Inspecciones: ninguna (las fotos van en el reporte de la inspección).
     *
     * @return Collection<int, PhotoSection>
     */
    public static function zonesFor(int $companyId, string $type): Collection
    {
        $codes = array_keys(PhotoZones::forType($type));

        if ($codes === []) {
            return new Collection;
        }

        $rows = static::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', $codes)->get()->keyBy('code');

        if ($rows->count() < count($codes)) {
            static::ensureZones($companyId, array_diff($codes, $rows->keys()->all()));
            $rows = static::withoutGlobalScopes()->where('company_id', $companyId)->whereIn('code', $codes)->get()->keyBy('code');
        }

        return new Collection(collect($codes)->map(fn (string $code) => $rows->get($code))->filter()->values()->all());
    }

    /** @param  array<int, string>  $codes */
    private static function ensureZones(int $companyId, array $codes): void
    {
        foreach ($codes as $code) {
            $label = PhotoZones::LABELS[$code];

            // Una sección vieja con el mismo nombre (sin código) pasa a ser la zona.
            $claimed = static::withoutGlobalScopes()->where('company_id', $companyId)->whereNull('code')
                ->where('name', $label)->update(['code' => $code, 'is_active' => true, 'updated_at' => now()]);

            if (! $claimed) {
                // insertOrIgnore + únicos (empresa, código) y (empresa, nombre): dos pedidos simultáneos no duplican.
                static::withoutGlobalScopes()->insertOrIgnore([
                    'company_id' => $companyId,
                    'code' => $code,
                    'name' => $label,
                    'position' => array_search($code, array_keys(PhotoZones::LABELS), true) + 1,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
