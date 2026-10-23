<?php

namespace App\Support\Marketplace;

use App\Models\Marketplace\ServiceCategory;
use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileTerm;
use App\Support\Countries;
use App\Support\Provinces;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Catálogo público de profesionales: búsqueda, filtros y paginación sobre
 * perfiles visibles (ServiceProfile::visible). Sin servicios externos.
 *
 * Marcas y zonas se guardan normalizadas en service_profile_terms (minúsculas,
 * sin acentos) y el texto libre en service_profiles.search_text: los filtros
 * son iguales en MySQL y SQLite y no dependen de funciones JSON.
 */
class ServiceDirectory
{
    public const PER_PAGE = 12;

    /** «Córdoba » → «cordoba». */
    public static function key(?string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii((string) $text))));
    }

    /** Zona de cobertura normalizada: «ar» (todo el país) o «ar:cordoba». */
    public static function areaKey(string $country, ?string $province = null): string
    {
        return Str::lower($country).($province ? ':'.self::key($province) : '');
    }

    /** @param array{q?: ?string, servicio?: ?string, marca?: ?string, pais?: ?string, provincia?: ?string, modalidad?: ?string} $filters */
    public static function search(array $filters): LengthAwarePaginator
    {
        $q = self::key($filters['q'] ?? null);
        $country = filled($filters['pais'] ?? null) ? Countries::normalize($filters['pais']) : null;
        $province = $country === 'AR' && in_array($filters['provincia'] ?? null, Provinces::LIST, true) ? $filters['provincia'] : null;
        $brand = self::key($filters['marca'] ?? null);
        $modality = $filters['modalidad'] ?? null;

        return ServiceProfile::visible()
            ->with(['categories' => fn ($c) => $c->where('is_active', true), 'company.latestSubscription'])
            ->when($q !== '', fn (Builder $query) => $query->where(function (Builder $w) use ($q) {
                foreach (array_slice(explode(' ', $q), 0, 6) as $word) {
                    $w->where('search_text', 'like', '%'.self::escapeLike($word).'%');
                }
            }))
            ->when(filled($filters['servicio'] ?? null), fn (Builder $query) => $query->whereHas('categories', fn (Builder $c) => $c->where('slug', $filters['servicio'])->where('is_active', true)))
            ->when($brand !== '', fn (Builder $query) => $query->whereHas('terms', fn (Builder $t) => $t->where('type', ServiceProfileTerm::BRAND)->where('term', 'like', self::escapeLike($brand).'%')))
            ->when($country !== null, fn (Builder $query) => $query->whereHas('terms', fn (Builder $t) => $t->where('type', ServiceProfileTerm::AREA)
                ->where(fn (Builder $w) => $province
                    ? $w->whereIn('term', [self::areaKey($country), self::areaKey($country, $province)])
                    : $w->where('term', self::areaKey($country))->orWhere('term', 'like', self::areaKey($country).':%'))))
            ->when(in_array($modality, ['onsite', 'remote'], true), fn (Builder $query) => $query->whereIn('modality', [$modality, 'both']))
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(self::PER_PAGE)->withQueryString();
    }

    /** Marcas que aparecen en perfiles visibles (para el filtro). */
    public static function brandOptions(): Collection
    {
        return ServiceProfileTerm::where('type', ServiceProfileTerm::BRAND)
            ->whereIn('service_profile_id', ServiceProfile::visible()->select('service_profiles.id'))
            ->selectRaw('term, min(label) as label')->groupBy('term')->orderBy('term')->limit(200)->pluck('label', 'term');
    }

    public static function categoryOptions(): Collection
    {
        return ServiceCategory::active()->pluck('name', 'slug');
    }

    /** Sin comodines de LIKE en lo que escribe el usuario (igual en MySQL y SQLite). */
    private static function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], '', $value);
    }
}
