<?php

namespace App\Models\Marketplace;

use App\Models\Company;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Perfil profesional («Profesionales y servicios»). Gratis, sin vencimiento y
 * sin límite de servicios. Pertenece a UNA cuenta: un proveedor del
 * marketplace (supplier_id) o una empresa de mantenimiento (company_id),
 * así nadie necesita otra cuenta para ofrecer servicios.
 *
 * Se ve en el catálogo público si (scopeVisible):
 * - su dueño lo publicó (status = published) y no lo archivó;
 * - Ascento lo aprobó (approved_at) y no lo ocultó ni suspendió (moderation);
 * - la cuenta dueña está habilitada (proveedor aprobado y sin cerrar, o
 *   empresa activa; el acceso vigente de la empresa se controla además en
 *   isVisible(), porque depende de su suscripción).
 *
 * Lo profesional es DECLARADO por el usuario: Ascento no verifica títulos,
 * matrículas ni certificaciones (no hay insignias de verificación).
 * Nunca se publican correos, teléfonos ni domicilios: el contacto es por
 * solicitud (ServiceContactRequest).
 */
class ServiceProfile extends Model
{
    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const ARCHIVED = 'archived';

    public const HIDDEN = 'hidden';

    public const SUSPENDED = 'suspended';

    public const KINDS = [
        'independent' => 'Profesional independiente',
        'workshop' => 'Taller',
        'service_company' => 'Empresa de servicios',
    ];

    public const MODALITIES = [
        'onsite' => 'Presencial',
        'remote' => 'Remota',
        'both' => 'Presencial y remota',
    ];

    public const STATUSES = [
        self::DRAFT => 'Borrador',
        self::PUBLISHED => 'Publicado',
        self::ARCHIVED => 'Archivado',
    ];

    public const MODERATIONS = [
        self::HIDDEN => 'Oculto por Ascento',
        self::SUSPENDED => 'Suspendido por Ascento',
    ];

    /** Lo que edita el dueño. Estado, moderación y aprobación: nunca. */
    protected $fillable = [
        'kind', 'public_name', 'headline', 'description', 'experience', 'modality', 'brands', 'services',
        'country', 'covers_whole_country', 'provinces', 'localities', 'website',
    ];

    protected $casts = [
        'brands' => 'array',
        'services' => 'array',
        'provinces' => 'array',
        'covers_whole_country' => 'boolean',
        'approved_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class)->withTrashed();
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ServiceCategory::class, 'service_profile_category')->orderBy('position')->orderBy('name');
    }

    public function terms(): HasMany
    {
        return $this->hasMany(ServiceProfileTerm::class);
    }

    public function contactRequests(): HasMany
    {
        return $this->hasMany(ServiceContactRequest::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ServiceProfileReport::class);
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query->where('service_profiles.status', self::PUBLISHED)
            ->whereNull('service_profiles.moderation')
            ->whereNotNull('service_profiles.approved_at')
            ->where(fn (Builder $owner) => $owner
                ->where(fn (Builder $q) => $q->whereNotNull('service_profiles.supplier_id')->whereHas('supplier', fn (Builder $s) => $s->operating()))
                ->orWhere(fn (Builder $q) => $q->whereNotNull('service_profiles.company_id')->whereHas('company', fn (Builder $c) => $c->withoutTrashed()->where('is_active', true))));
    }

    /** ¿Se ve y recibe solicitudes? (la regla de scopeVisible + acceso vigente de la empresa dueña) */
    public function isVisible(): bool
    {
        if (! $this->exists || ! static::visible()->whereKey($this->id)->exists()) {
            return false;
        }

        return $this->company_id === null || (bool) $this->company?->hasActiveAccess();
    }

    public function ownerLabel(): string
    {
        return $this->company_id !== null ? 'Empresa de mantenimiento' : 'Cuenta de proveedor';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function modalityLabel(): string
    {
        return self::MODALITIES[$this->modality] ?? $this->modality;
    }

    /** Estado para el dueño: lo que decidió él y, encima, lo que decidió Ascento. */
    public function stateLabel(): string
    {
        return match (true) {
            $this->moderation !== null => self::MODERATIONS[$this->moderation],
            $this->status === self::PUBLISHED && $this->approved_at === null => 'Esperando la revisión de Ascento',
            default => self::STATUSES[$this->status] ?? $this->status,
        };
    }

    public function stateTone(): string
    {
        return match (true) {
            $this->moderation !== null => 'bad',
            $this->status === self::PUBLISHED && $this->approved_at !== null => 'ok',
            $this->status === self::PUBLISHED => 'warn',
            default => '',
        };
    }

    /** «Córdoba, Santa Fe · Río Cuarto» o «Todo Argentina». */
    public function coverageLabel(): string
    {
        $country = Countries::name($this->country);
        $areas = $this->covers_whole_country ? 'Todo '.$country : implode(', ', (array) $this->provinces);

        return trim(collect([$areas, $this->localities])->filter()->implode(' · ')) ?: $country;
    }
}
