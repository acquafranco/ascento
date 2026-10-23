<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\Marketplace\ServiceCategory;
use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\ServiceProfile;
use App\Services\Marketplace\ServiceContacts;
use App\Services\Marketplace\ServiceProfiles;
use App\Support\Access\Access;
use App\Support\Countries;
use App\Support\Marketplace\ServiceAccess;
use App\Support\Provinces;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * «Mi perfil profesional» de una empresa de mantenimiento: la misma cuenta
 * (sin otro login) ofrece servicios especializados a otras empresas.
 * Solo el dueño (admin) de la empresa lo edita y ve las consultas recibidas.
 * Las reglas están en ServiceProfiles y ServiceContacts.
 */
class MyServiceProfile extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Mi perfil profesional';

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'mi-perfil-profesional';

    protected static ?string $title = 'Mi perfil profesional';

    protected string $view = 'filament.pages.marketplace.my-service-profile';

    #[Url]
    public ?int $solicitud = null;

    #[Url]
    public string $estado = 'abiertas';

    public array $data = [];

    public string $replyText = '';

    public static function canAccess(): bool
    {
        return Access::isOwner(auth()->user());
    }

    public static function getNavigationBadge(): ?string
    {
        $profile = Access::isOwner(auth()->user()) ? ServiceAccess::profileOf(auth()->user()) : null;
        $open = $profile ? ServiceContactRequest::forProfile($profile->id)->where('status', ServiceContactRequest::OPEN)->count() : 0;

        return $open > 0 ? (string) $open : null;
    }

    public function getSubheading(): ?string
    {
        return 'Si tu empresa ofrece servicios especializados (reparación de placas, asistencia, inspecciones…), publicá un perfil gratis en «Profesionales y servicios» con esta misma cuenta.';
    }

    public function mount(): void
    {
        $profile = ServiceAccess::profileOf(auth()->user());
        $company = auth()->user()->company;
        $this->data = [
            'kind' => $profile->kind ?? 'service_company',
            'public_name' => $profile->public_name ?? (string) $company?->name,
            'headline' => $profile->headline ?? '',
            'description' => $profile->description ?? '',
            'experience' => $profile->experience ?? '',
            'modality' => $profile->modality ?? 'onsite',
            'categories' => $profile ? $profile->categories->pluck('id')->map(fn ($id) => (string) $id)->all() : [],
            'services' => implode(', ', (array) ($profile->services ?? [])),
            'brands' => implode(', ', (array) ($profile->brands ?? [])),
            'country' => $profile->country ?? Countries::normalize($company?->country),
            'covers_whole_country' => (bool) ($profile->covers_whole_country ?? false),
            'provinces' => (array) ($profile->provinces ?? array_filter([$company?->province])),
            'localities' => $profile->localities ?? '',
            'website' => $profile->website ?? '',
        ];
    }

    public function save(): void
    {
        $this->resetErrorBag();
        $rules = collect(ServiceProfiles::rules())->mapWithKeys(fn ($r, $k) => ['data.'.$k => $r])->all();
        $messages = collect(ServiceProfiles::messages())->mapWithKeys(fn ($m, $k) => ['data.'.$k => $m])->all();
        $data = $this->validate($rules, $messages)['data'];
        $data['covers_whole_country'] = (bool) ($data['covers_whole_country'] ?? false);
        app(ServiceProfiles::class)->save(auth()->user(), $data);
        Notification::make()->title('Perfil profesional guardado')->success()->send();
    }

    public function publish(): void
    {
        $profile = ServiceAccess::profileOf(auth()->user());
        abort_unless($profile, 404);
        app(ServiceProfiles::class)->publish(auth()->user(), $profile);
        Notification::make()->title($profile->fresh()->approved_at ? 'Perfil publicado' : 'Perfil enviado a revisión')->body($profile->fresh()->approved_at ? null : 'Se va a ver en el catálogo cuando Ascento lo apruebe.')->success()->send();
    }

    public function archive(): void
    {
        $profile = ServiceAccess::profileOf(auth()->user());
        abort_unless($profile, 404);
        app(ServiceProfiles::class)->archive(auth()->user(), $profile);
        Notification::make()->title('Perfil archivado')->body('Ya no se ve en el catálogo. Las solicitudes se conservan.')->success()->send();
    }

    private function current(): ServiceContactRequest
    {
        $profile = ServiceAccess::profileOf(auth()->user());
        abort_unless($profile instanceof ServiceProfile, 404);

        return ServiceContactRequest::forProfile($profile->id)->with('messages')->findOrFail((int) $this->solicitud);
    }

    public function reply(): void
    {
        $this->resetErrorBag();
        app(ServiceContacts::class)->reply(auth()->user(), $this->current(), $this->replyText);
        $this->replyText = '';
        Notification::make()->title('Respuesta enviada')->body('Le avisamos a la empresa.')->success()->send();
    }

    public function close(): void
    {
        app(ServiceContacts::class)->close(auth()->user(), $this->current());
        Notification::make()->title('Solicitud cerrada')->success()->send();
    }

    protected function getViewData(): array
    {
        $profile = ServiceAccess::profileOf(auth()->user());
        $base = ['profile' => $profile, 'kinds' => ServiceProfile::KINDS, 'modalities' => ServiceProfile::MODALITIES,
            'categories' => ServiceCategory::active()->get(), 'countries' => Countries::options(), 'provinces' => Provinces::LIST];

        if ($this->solicitud !== null) {
            return $base + ['mode' => 'detail', 'row' => $this->current()];
        }
        $tab = $this->estado === 'cerradas' ? 'cerradas' : 'abiertas';

        return $base + [
            'mode' => 'list',
            'tab' => $tab,
            'rows' => $profile ? ServiceContactRequest::forProfile($profile->id)
                ->when($tab === 'abiertas', fn ($q) => $q->where('status', '!=', ServiceContactRequest::CLOSED), fn ($q) => $q->where('status', ServiceContactRequest::CLOSED))
                ->latest('last_message_at')->limit(100)->get() : collect(),
        ];
    }
}
