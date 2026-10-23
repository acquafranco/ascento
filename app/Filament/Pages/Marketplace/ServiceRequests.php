<?php

namespace App\Filament\Pages\Marketplace;

use App\Models\Marketplace\ServiceContactRequest;
use App\Models\Marketplace\ServiceProfile;
use App\Models\Marketplace\ServiceProfileReport;
use App\Services\Marketplace\ServiceContacts;
use App\Support\Marketplace\ServiceAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;

/**
 * «Profesionales» en el panel de la empresa: enviar una solicitud de contacto
 * a un perfil del catálogo público (?perfil=slug), seguir las enviadas
 * (?solicitud=id) y reportar un perfil. Aparte de los pedidos de repuestos.
 * Solo las solicitudes de ESTA empresa (un id ajeno da 404).
 */
class ServiceRequests extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static string|\UnitEnum|null $navigationGroup = 'Operaciones';

    protected static ?string $navigationLabel = 'Profesionales';

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'profesionales';

    protected static ?string $title = 'Profesionales y servicios';

    protected string $view = 'filament.pages.marketplace.service-requests';

    #[Url]
    public ?string $perfil = null;

    #[Url]
    public ?int $solicitud = null;

    #[Url]
    public string $estado = 'abiertas';

    public array $form = ['contact_name' => '', 'contact_email' => '', 'contact_phone' => '', 'subject' => '', 'need' => '', 'modality' => '', 'location' => ''];

    /** Clave de envío: dos clics (o un reenvío) = una sola solicitud. */
    public string $key = '';

    public string $replyText = '';

    public string $reportReason = '';

    public string $reportDetails = '';

    public static function canAccess(): bool
    {
        return ServiceAccess::canSeeSent(auth()->user());
    }

    public function getSubheading(): ?string
    {
        return 'Contactá especialistas para reparaciones, asistencia técnica, inspecciones o consultoría. Es gratis; Ascento no participa de la contratación ni procesa pagos.';
    }

    public function mount(): void
    {
        $user = auth()->user();
        $company = $user->company;
        $this->form['contact_name'] = (string) $user->name;
        $this->form['contact_email'] = (string) $user->email;
        $this->form['contact_phone'] = (string) ($company?->phone ?? '');
        $this->form['location'] = trim(collect([$company?->city, $company?->province])->filter()->implode(', '));
        $this->key = (string) Str::uuid();
    }

    private function target(): ServiceProfile
    {
        $profile = ServiceProfile::where('slug', (string) $this->perfil)->first();
        abort_unless($profile && $profile->isVisible(), 404);

        return $profile;
    }

    private function current(): ServiceContactRequest
    {
        return ServiceContactRequest::sentBy((int) auth()->user()->company_id)->with(['messages', 'profile'])->findOrFail((int) $this->solicitud);
    }

    public function send(): void
    {
        $user = auth()->user();
        abort_unless(ServiceAccess::canRequest($user), 403);
        $this->resetErrorBag();
        $this->validate([
            'form.contact_name' => ['required', 'string', 'max:120'],
            'form.contact_email' => ['required', 'email', 'max:255'],
            'form.contact_phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+()\s.\-]{6,40}$/'],
            'form.subject' => ['required', 'string', 'min:5', 'max:160'],
            'form.need' => ['required', 'string', 'min:20', 'max:3000'],
            'form.modality' => ['nullable', 'in:onsite,remote,both'],
            'form.location' => ['nullable', 'string', 'max:160'],
        ], [
            'form.contact_name.required' => 'Poné el nombre de quien recibe la respuesta.',
            'form.contact_email.required' => 'Poné un correo para que el profesional te responda.',
            'form.subject.required' => 'Poné un título corto (por ejemplo: «Placa de maniobra que no arranca»).',
            'form.need.required' => 'Contá qué necesitás.',
            'form.need.min' => 'Contá un poco más (equipo, marca, falla o servicio que necesitás).',
            'form.contact_phone.regex' => 'Revisá el teléfono.',
        ]);

        $request = app(ServiceContacts::class)->create($user, $this->target(), $this->form, $this->key);
        $this->key = (string) Str::uuid();
        $this->form['subject'] = $this->form['need'] = '';

        Notification::make()->title('Solicitud '.$request->reference.' enviada')->body('Te avisamos cuando '.$request->profile_name.' responda.')->success()->send();
        $this->perfil = null;
        $this->solicitud = $request->id;
    }

    public function reply(): void
    {
        $this->resetErrorBag();
        app(ServiceContacts::class)->reply(auth()->user(), $this->current(), $this->replyText);
        $this->replyText = '';
        Notification::make()->title('Mensaje enviado')->success()->send();
    }

    public function close(): void
    {
        app(ServiceContacts::class)->close(auth()->user(), $this->current());
        Notification::make()->title('Solicitud cerrada')->body('Queda en tu historial.')->success()->send();
    }

    public function report(): void
    {
        $this->resetErrorBag();
        $this->validate(['reportReason' => ['required', 'in:'.implode(',', array_keys(ServiceProfileReport::REASONS))], 'reportDetails' => ['nullable', 'string', 'max:2000']],
            ['reportReason.required' => 'Elegí un motivo.']);
        $profile = $this->solicitud !== null ? $this->current()->profile : $this->target();
        app(ServiceContacts::class)->report(auth()->user(), $profile, $this->reportReason, $this->reportDetails);
        $this->reportReason = $this->reportDetails = '';
        Notification::make()->title('Gracias: el equipo de Ascento va a revisar el perfil')->success()->send();
    }

    protected function getViewData(): array
    {
        $user = auth()->user();
        $base = ['canRequest' => ServiceAccess::canRequest($user), 'reasons' => ServiceProfileReport::REASONS];

        if ($this->solicitud !== null) {
            return $base + ['mode' => 'detail', 'row' => $this->current()];
        }
        if (filled($this->perfil)) {
            $profile = $this->target();
            $open = ServiceContactRequest::sentBy((int) $user->company_id)->forProfile($profile->id)->where('status', '!=', ServiceContactRequest::CLOSED)->first();

            return $base + ['mode' => 'compose', 'profile' => $profile, 'open' => $open, 'own' => (int) $profile->company_id === (int) $user->company_id];
        }
        $tab = $this->estado === 'cerradas' ? 'cerradas' : 'abiertas';

        return $base + [
            'mode' => 'list',
            'tab' => $tab,
            'rows' => ServiceContactRequest::sentBy((int) $user->company_id)
                ->when($tab === 'abiertas', fn ($q) => $q->where('status', '!=', ServiceContactRequest::CLOSED), fn ($q) => $q->where('status', ServiceContactRequest::CLOSED))
                ->latest('last_message_at')->limit(100)->get(),
        ];
    }
}
