<?php

namespace App\Services\Marketplace;

use App\Models\Marketplace\ServiceCategory;
use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileTerm;
use App\Models\Marketplace\Supplier;
use App\Models\User;
use App\Services\Activity\PlatformAudit;
use App\Support\Countries;
use App\Support\Marketplace\ServiceAccess;
use App\Support\Marketplace\ServiceDirectory;
use App\Support\Provinces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Perfiles profesionales: alta y edición por su dueño, publicación,
 * archivo y moderación del SuperAdmin. Todo gratis y sin vencimiento: no
 * hay planes, límites de servicios ni cobros.
 *
 * Estados (se distinguen, nada se borra):
 * - del dueño: borrador → publicado ⇄ archivado (retirarlo del catálogo);
 * - de Ascento: aprobado (la primera vez que se publica), oculto (sale del
 *   catálogo; el dueño puede corregirlo) o suspendido (sale del catálogo, no
 *   recibe solicitudes nuevas y el dueño no puede editarlo ni responder).
 *   Las solicitudes y su historial se conservan siempre.
 */
class ServiceProfiles
{
    public function __construct(private MarketplaceNotifier $notify) {}

    /** Reglas del formulario (compartidas por el área del proveedor y el panel de la empresa). */
    public static function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(array_keys(ServiceProfile::KINDS))],
            'public_name' => ['required', 'string', 'min:3', 'max:160'],
            'headline' => ['nullable', 'string', 'max:160'],
            'description' => ['required', 'string', 'min:30', 'max:3000'],
            'experience' => ['nullable', 'string', 'max:2000'],
            'modality' => ['required', Rule::in(array_keys(ServiceProfile::MODALITIES))],
            'categories' => ['required', 'array', 'min:1', 'max:15'],
            'categories.*' => ['integer', Rule::exists('service_categories', 'id')->where('is_active', true)],
            'services' => ['nullable', 'string', 'max:1500'],
            'brands' => ['nullable', 'string', 'max:500'],
            'country' => ['required', Rule::in(array_keys(Countries::LATAM))],
            'covers_whole_country' => ['boolean'],
            'provinces' => ['nullable', 'array', 'max:'.count(Provinces::LIST)],
            'provinces.*' => ['string', Rule::in(Provinces::LIST)],
            'localities' => ['nullable', 'string', 'max:255'],
            'website' => ['nullable', 'url:https,http', 'max:255'],
        ];
    }

    public static function messages(): array
    {
        return [
            'categories.required' => 'Elegí al menos una especialidad.',
            'description.min' => 'Contá un poco más sobre lo que hacés (al menos 30 caracteres).',
            'website.url' => 'Revisá la dirección (empieza con https://).',
        ];
    }

    /** Alta o edición del perfil de la cuenta del usuario (datos ya validados con rules()). */
    public function save(User $user, array $data): ServiceProfile
    {
        abort_unless(ServiceAccess::canOwnProfile($user), 403);
        $this->ensureAccountCanEdit($user);

        $profile = ServiceAccess::profileOf($user);
        if ($profile?->moderation === ServiceProfile::SUSPENDED) {
            throw ValidationException::withMessages(['perfil' => 'Tu perfil está suspendido por Ascento: no se puede editar. Escribinos para revisarlo.']);
        }

        $country = Countries::normalize($data['country'] ?? null);
        $whole = (bool) ($data['covers_whole_country'] ?? false);
        $provinces = $country === 'AR' && ! $whole ? array_values(array_intersect(Provinces::LIST, (array) ($data['provinces'] ?? []))) : [];
        if (! $whole && $provinces === [] && blank($data['localities'] ?? null) && $data['modality'] !== 'remote') {
            throw ValidationException::withMessages(['provinces' => 'Indicá dónde trabajás: todo el país, provincias o localidades.']);
        }

        return DB::transaction(function () use ($user, $profile, $data, $country, $whole, $provinces) {
            $profile ??= new ServiceProfile;
            $profile->fill([
                'kind' => $data['kind'],
                'public_name' => trim($data['public_name']),
                'headline' => self::clean($data['headline'] ?? null),
                'description' => trim($data['description']),
                'experience' => self::clean($data['experience'] ?? null),
                'modality' => $data['modality'],
                'services' => self::list($data['services'] ?? null, 30, 120),
                'brands' => self::list($data['brands'] ?? null, 30, 40),
                'country' => $country,
                'covers_whole_country' => $whole,
                'provinces' => $provinces,
                'localities' => self::clean($data['localities'] ?? null),
                'website' => self::clean($data['website'] ?? null),
            ]);
            if (! $profile->exists) {
                $profile->forceFill([
                    'slug' => Str::limit(Str::slug($data['public_name']), 170, '').'-'.Str::lower(Str::random(5)),
                    'supplier_id' => $user->isSupplierUser() ? $user->supplier_id : null,
                    'company_id' => $user->isSupplierUser() ? null : $user->company_id,
                    'status' => ServiceProfile::DRAFT,
                ]);
            }
            $profile->save();

            $categories = ServiceCategory::whereIn('id', (array) $data['categories'])->where('is_active', true)->pluck('id');
            // Las especialidades que el SuperAdmin desactivó se conservan en el perfil (no se ven ni filtran).
            $inactive = $profile->categories()->where('is_active', false)->pluck('service_categories.id');
            $profile->categories()->sync($categories->merge($inactive)->unique()->values()->all());
            $this->index($profile->fresh('categories'));

            return $profile->fresh();
        });
    }

    /** Publicar: la primera vez queda esperando la revisión de Ascento (no se ve hasta aprobarlo). */
    public function publish(User $user, ServiceProfile $profile): void
    {
        abort_unless(ServiceAccess::owns($user, $profile), 404);
        $this->ensureAccountCanEdit($user);
        if ($profile->moderation === ServiceProfile::SUSPENDED) {
            throw ValidationException::withMessages(['perfil' => 'Tu perfil está suspendido por Ascento: no se puede publicar.']);
        }
        $profile->forceFill(['status' => ServiceProfile::PUBLISHED, 'published_at' => $profile->published_at ?? now()])->save();

        if ($profile->approved_at === null) {
            $this->notify->team('Perfil profesional para revisar', '«'.$profile->public_name.'» ('.$profile->ownerLabel().') pidió publicar su perfil profesional.', '/admin/marketplace/profesionales/'.$profile->id, 'svc-profile-review:'.$profile->id, mail: true);
        }
    }

    /** Archivar: el dueño lo retira del catálogo (puede volver a publicarlo). Las solicitudes se conservan. */
    public function archive(User $user, ServiceProfile $profile): void
    {
        abort_unless(ServiceAccess::owns($user, $profile), 404);
        $profile->forceFill(['status' => ServiceProfile::ARCHIVED])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | MODERACIÓN (SuperAdmin, con auditoría)
    |--------------------------------------------------------------------------
    */

    public function approve(User $admin, ServiceProfile $profile): void
    {
        abort_unless($admin->isSuperAdmin(), 403);
        $profile->forceFill(['approved_at' => $profile->approved_at ?? now(), 'approved_by' => $profile->approved_by ?? $admin->id])->save();
        PlatformAudit::record('marketplace.service_profile.approved', $profile, [], $profile->public_name);
        $this->notify->serviceOwner($profile, 'Aprobamos tu perfil profesional', $profile->status === ServiceProfile::PUBLISHED
            ? 'Tu perfil «'.$profile->public_name.'» ya se ve en «Profesionales y servicios».'
            : 'Tu perfil «'.$profile->public_name.'» está aprobado: se va a ver cuando lo publiques.', 'approved:'.$profile->id);
    }

    /** Ocultar (sale del catálogo) o suspender (además, sin solicitudes nuevas ni edición). */
    public function moderate(User $admin, ServiceProfile $profile, string $moderation, string $reason): void
    {
        abort_unless($admin->isSuperAdmin(), 403);
        abort_unless(in_array($moderation, [ServiceProfile::HIDDEN, ServiceProfile::SUSPENDED], true), 422);
        $from = $profile->moderation;
        $profile->forceFill(['moderation' => $moderation, 'moderation_reason' => trim($reason)])->save();
        PlatformAudit::record('marketplace.service_profile.'.$moderation, $profile, ['from' => $from, 'reason' => $reason], $profile->public_name);
        $this->notify->serviceOwner($profile, $moderation === ServiceProfile::SUSPENDED ? 'Suspendimos tu perfil profesional' : 'Ocultamos tu perfil profesional',
            ($moderation === ServiceProfile::SUSPENDED
                ? 'Tu perfil dejó de verse y no recibe solicitudes nuevas. Tus solicitudes y su historial se conservan.'
                : 'Tu perfil dejó de verse en el catálogo. Podés corregirlo y escribirnos para que lo revisemos.').' Motivo: '.trim($reason),
            $moderation.':'.$profile->id.':'.now()->timestamp);
    }

    /** Quitar la ocultación o suspensión (vuelve a verse si el dueño lo tiene publicado). */
    public function restore(User $admin, ServiceProfile $profile): void
    {
        abort_unless($admin->isSuperAdmin(), 403);
        $from = $profile->moderation;
        $profile->forceFill(['moderation' => null, 'moderation_reason' => null])->save();
        PlatformAudit::record('marketplace.service_profile.restored', $profile, ['from' => $from], $profile->public_name);
        $this->notify->serviceOwner($profile, 'Reactivamos tu perfil profesional', 'Tu perfil «'.$profile->public_name.'» vuelve a estar habilitado'.($profile->status === ServiceProfile::PUBLISHED ? ' y se ve en el catálogo.' : '; se va a ver cuando lo publiques.'), 'restored:'.$profile->id.':'.now()->timestamp);
    }

    /** Marcas, zonas y texto de búsqueda normalizados (filtros del catálogo). */
    public function index(ServiceProfile $profile): void
    {
        $terms = collect((array) $profile->brands)->map(fn ($b) => ['type' => ServiceProfileTerm::BRAND, 'term' => Str::limit(ServiceDirectory::key($b), 120, ''), 'label' => Str::limit($b, 120, '')]);
        $areas = $profile->covers_whole_country
            ? collect([['type' => ServiceProfileTerm::AREA, 'term' => ServiceDirectory::areaKey($profile->country), 'label' => 'Todo '.Countries::name($profile->country)]])
            : collect((array) $profile->provinces)->map(fn ($p) => ['type' => ServiceProfileTerm::AREA, 'term' => ServiceDirectory::areaKey($profile->country, $p), 'label' => $p]);
        $rows = $terms->merge($areas)->unique(fn ($t) => $t['type'].'|'.$t['term'])->map(fn ($t) => $t + ['service_profile_id' => $profile->id])->values();

        ServiceProfileTerm::where('service_profile_id', $profile->id)->delete();
        if ($rows->isNotEmpty()) {
            ServiceProfileTerm::insert($rows->all());
        }

        $text = collect([$profile->public_name, $profile->headline, $profile->description, $profile->experience, $profile->localities,
            implode(' ', (array) $profile->services), implode(' ', (array) $profile->brands), implode(' ', (array) $profile->provinces),
            $profile->categories->pluck('name')->implode(' '), $profile->kindLabel()])->filter()->implode(' ');
        $profile->forceFill(['search_text' => Str::limit(ServiceDirectory::key($text), 60000, '')])->saveQuietly();
    }

    /** Cuenta de proveedor rechazada, suspendida o cerrada: puede mirar, no cambiar. */
    private function ensureAccountCanEdit(User $user): void
    {
        $supplier = $user->isSupplierUser() ? $user->supplier : null;
        if ($supplier && ($supplier->closed_at !== null || in_array($supplier->status, [Supplier::REJECTED, Supplier::SUSPENDED], true))) {
            throw ValidationException::withMessages(['perfil' => 'Tu cuenta no está habilitada para hacer cambios.']);
        }
    }

    private static function clean(?string $value): ?string
    {
        return filled($value) ? trim($value) : null;
    }

    /** «Diagnóstico, Reparación de placas» → lista corta, sin repetidos. */
    private static function list(?string $text, int $max, int $length): array
    {
        return collect(preg_split('/[,;\n]+/', (string) $text))
            ->map(fn ($v) => Str::limit(trim(preg_replace('/\s+/', ' ', $v)), $length, ''))
            ->filter()->unique(fn ($v) => mb_strtolower($v))->take($max)->values()->all();
    }
}
