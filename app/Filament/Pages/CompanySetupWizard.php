<?php

namespace App\Filament\Pages;

use App\Enums\PlanLimit;
use App\Exceptions\PlanLimitReachedException;
use App\Filament\Resources\Buildings\Schemas\BuildingForm;
use App\Filament\Resources\Clients\Schemas\ClientForm;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\Schemas\WorkOrderForm;
use App\Models\Building;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Onboarding\CompanySetup;
use App\Support\Auth\EmailGate;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * Configuración inicial guiada: un asistente propio, sin salir a las
 * pantallas del menú. Cada paso se completa acá mismo y se guarda al
 * continuar, con los MISMOS campos y reglas de los formularios del panel
 * (se reutilizan sus piezas) y la lógica de CompanySetup.
 *
 * 1. Tu empresa (datos y marca) · 2. Tu técnico · 3. Tu cliente ·
 * 4. Tu edificio y sus ascensores · 5. Tu primera orden, asignada a ese técnico.
 *
 * Solo el dueño de la empresa. Se puede salir en cualquier momento («Seguir
 * después») y retomar donde quedó: el avance son los registros guardados.
 */
class CompanySetupWizard extends Page
{
    protected static string $layout = 'filament-panels::components.layout.simple';

    protected string $view = 'filament.pages.company-setup-wizard';

    protected static ?string $slug = 'configuracion-inicial';

    protected static ?string $title = 'Configuración inicial';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $screen = 'welcome';

    public ?array $companyData = [];

    public ?array $brandData = [];

    public ?array $technicianData = [];

    public ?array $clientData = [];

    public ?array $buildingData = [];

    public ?array $orderData = [];

    public static function canAccess(): bool
    {
        return CompanySetup::appliesTo(auth()->user());
    }

    protected function getLayoutData(): array
    {
        return ['hasTopbar' => false, 'maxContentWidth' => Width::TwoExtraLarge];
    }

    private function company(): Company
    {
        $user = auth()->user();
        abort_unless(CompanySetup::appliesTo($user), 403);

        return $user->company->fresh();
    }

    private function setup(): CompanySetup
    {
        return app(CompanySetup::class);
    }

    public function mount(): void
    {
        $company = $this->company();

        if ($company->setup_status === CompanySetup::COMPLETED) {
            $this->redirect(CompanySetup::dashboardUrl());

            return;
        }

        // Abrir el asistente es retomarlo (también si se había omitido).
        $this->setup()->resume($company);
        $this->screen = $this->setup()->progress($company->fresh())['next'];
        $this->fillScreen();
    }

    /* ------------------------------------------------------------ FORMULARIOS */

    public function companyForm(Schema $schema): Schema
    {
        return $schema->statePath('companyData')->components([
            CompanySettings::nameField()->label('Nombre de la empresa')->autofocus(),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                CompanySettings::phoneField(),
                CompanySettings::emailField(),
            ]),
        ]);
    }

    public function brandForm(Schema $schema): Schema
    {
        return $schema->statePath('brandData')->components([
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                CompanySettings::logoField()->helperText('PNG, JPG o WEBP, hasta 2 MB. Sale en tus remitos y presupuestos.'),
                CompanySettings::colorField()->helperText('El color de tus documentos y del portal de tus clientes.'),
            ]),
        ]);
    }

    public function technicianForm(Schema $schema): Schema
    {
        return $schema->statePath('technicianData')->model(User::class)->operation('create')->components([
            Grid::make(['default' => 1, 'sm' => 2])->schema(UserResource::technicianFields()),
        ]);
    }

    public function clientForm(Schema $schema): Schema
    {
        $fields = collect(ClientForm::basicFields())->keyBy(fn ($field) => $field->getName());

        return $schema->statePath('clientData')->model(Client::class)->operation('create')->components([
            $fields['name']->autofocus(),
            $fields['type'],
            Section::make('Contacto (opcional)')->collapsible()->collapsed()->compact()
                ->schema([$fields['contact_person'], Grid::make(['default' => 1, 'sm' => 2])->schema([$fields['phone'], $fields['email']])]),
        ]);
    }

    public function buildingForm(Schema $schema): Schema
    {
        return $schema->statePath('buildingData')->model(Building::class)->operation('create')->components([
            ...BuildingForm::addressFields(),
            BuildingForm::equipmentSection()->description('Indicá cuántos hay y la tracción de cada uno. Hace falta al menos uno.'),
        ]);
    }

    public function orderForm(Schema $schema): Schema
    {
        return $schema->statePath('orderData')->model(WorkOrder::class)->operation('create')->components([
            // Solo para listar los equipos: al guardar se usa el edificio del paso anterior.
            Hidden::make('building_id'),
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                WorkOrderForm::unitField()->placeholder('Elegí el equipo'),
                WorkOrderForm::typeField(),
            ]),
            WorkOrderForm::priorityField(),
            WorkOrderForm::notesField()->label('¿Qué hay que hacer?')->rows(3),
        ]);
    }

    /** Carga la pantalla actual con lo ya guardado (la empresa) o con el contexto del paso. */
    private function fillScreen(): void
    {
        $company = $this->company();
        $progress = $this->setup()->progress($company);

        match ($this->screen) {
            'company' => $this->companyForm->fill($company->only(['name', 'phone', 'email'])),
            'brand' => $this->brandForm->fill($company->only(['logo', 'primary_color'])),
            'technician' => $progress['technician'] ? null : $this->technicianForm->fill(),
            'client' => $progress['client'] ? null : $this->clientForm->fill(),
            'building' => $progress['building'] ? null : $this->buildingForm->fill(),
            'work_order' => $progress['order'] ? null : $this->orderForm->fill(['building_id' => $progress['building']?->id, 'type' => 'maintenance', 'priority' => 'medium']),
            default => null,
        };
    }

    /* ---------------------------------------------------------- NAVEGACIÓN */

    /** Guarda la pantalla actual (si corresponde) y pasa a la siguiente. */
    public function next(): void
    {
        $company = $this->company();
        $progress = $this->setup()->progress($company);

        try {
            match ($this->screen) {
                'welcome' => $this->setup()->acknowledge($company, 'welcome'),
                'company' => $this->setup()->saveCompany($company, $this->companyForm->getState(), 'company'),
                'brand' => $this->setup()->saveCompany($company, $this->brandForm->getState(), 'brand'),
                'technician' => $progress['technician'] ?? $this->guarded(PlanLimit::Technicians, fn () => $this->setup()->createTechnician($company, $this->technicianForm->getState())),
                'client' => $progress['client'] ?? $this->guarded(PlanLimit::Clients, fn () => $this->setup()->createClient($company, $this->clientForm->getState())),
                'building' => $progress['building'] ?? $this->guarded(PlanLimit::Buildings, fn () => $this->setup()->createBuilding($company, $this->buildingForm->getState(), $this->buildingData['address_search'] ?? null)),
                'work_order' => $progress['order'] ?? $this->setup()->createWorkOrder($company, $this->orderForm->getState()),
                default => null,
            };
        } catch (PlanLimitReachedException $e) {
            PlanUpsell::limitNotification($e->company, $e->limit)->send();

            return;
        } catch (ValidationException $e) {
            // Los errores de un campo de la pantalla («companyData.name»…) se muestran
            // en el campo; los del servidor (p. ej. falta el paso anterior), como aviso.
            if (str_ends_with((string) strstr((string) array_key_first($e->errors()), '.', true), 'Data')) {
                throw $e;
            }
            Notification::make()->title(collect($e->errors())->flatten()->first())->warning()->send();

            return;
        }

        $this->go($this->setup()->progress($company->fresh())['next'] === 'done' && $this->screen === 'work_order'
            ? 'done'
            : $this->following($this->screen));
    }

    /** Vuelve a la pantalla anterior (sin guardar ni borrar nada). */
    public function back(): void
    {
        $index = array_search($this->screen, CompanySetup::SCREENS, true);
        if ($index > 1) {
            $this->go(CompanySetup::SCREENS[$index - 1]);
        }
    }

    private function following(string $screen): string
    {
        $index = array_search($screen, CompanySetup::SCREENS, true);

        return CompanySetup::SCREENS[min($index + 1, count(CompanySetup::SCREENS) - 1)];
    }

    /** Nunca más allá del primer paso sin hacer (cada paso necesita el anterior). */
    private function go(string $screen): void
    {
        $next = $this->setup()->progress($this->company())['next'];
        $limit = array_search($next, CompanySetup::SCREENS, true);
        $wanted = array_search($screen, CompanySetup::SCREENS, true);

        $this->screen = CompanySetup::SCREENS[min($wanted, $limit)];
        $this->resetErrorBag();
        $this->fillScreen();
    }

    /** Límite del plan antes de abrir el alta (el modelo lo vuelve a verificar al crear). */
    private function guarded(PlanLimit $limit, callable $create): mixed
    {
        $company = $this->company();
        if (! PlanGuard::for($company)->canAdd($limit)) {
            throw new PlanLimitReachedException($company, $limit);
        }

        return $create();
    }

    /** «Seguir después»: al inicio, sin perder nada; no se vuelve a abrir sola en esta sesión. */
    public function pause(): void
    {
        $this->company();
        session()->put(CompanySetup::PAUSED_SESSION_KEY, true);
        $this->redirect(CompanySetup::dashboardUrl());
    }

    /** «No la necesito»: no se abre más (se retoma desde Ayuda o el botón «?»). */
    public function skip(): void
    {
        $this->setup()->skip($this->company());
        $this->redirect(CompanySetup::dashboardUrl());
    }

    /**
     * El dueño de una empresa nueva confirma su correo antes de usar el servicio
     * (EmailGate): hasta entonces no sale de esta pantalla.
     */
    private function mustVerify(): bool
    {
        $user = auth()->user()->fresh();

        return EmailGate::appliesTo($user) && ! $user->hasVerifiedEmail();
    }

    /** Reenviar el enlace de confirmación (3 cada 10 minutos, como en «Verificá tu email»). */
    public function resendVerification(): void
    {
        $user = auth()->user()->fresh();
        if ($user->hasVerifiedEmail()) {
            return;
        }

        $key = 'verification-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            Notification::make()->title('Ya te mandamos varios enlaces hace poco')->body('Esperá unos minutos y volvé a pedirlo. Revisá también la carpeta de spam.')->warning()->send();

            return;
        }
        RateLimiter::hit($key, 600);
        $user->sendEmailVerificationNotification();

        Notification::make()->title('Te mandamos un enlace nuevo')->body('Revisá '.$user->email.' (y la carpeta de spam).')->success()->send();
    }

    public function finish(): void
    {
        if ($this->mustVerify()) {
            Notification::make()->title('Todavía no confirmaste tu correo')
                ->body('Abrí el enlace que te mandamos a '.auth()->user()->email.' y después tocá «Ya confirmé mi correo».')
                ->warning()->send();

            return;
        }

        abort_unless($this->setup()->complete($this->company()), 422);

        // Sigue la ayuda de inicio: el recorrido corto del menú y los próximos pasos.
        $this->redirect(CompanySetup::dashboardUrl().'?configuracion=lista');
    }

    protected function getViewData(): array
    {
        $company = $this->company();
        $progress = $this->setup()->progress($company);
        $step = CompanySetup::STEP_OF[$this->screen] ?? null;

        return [
            'progress' => $progress,
            'company' => $company,
            'step' => $step,
            'steps' => CompanySetup::STEP_TITLES,
            'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $company->primary_color) ? $company->primary_color : '#FF6600',
            'logo' => $company->logo ? asset('storage/'.$company->logo) : null,
            'canGoBack' => array_search($this->screen, CompanySetup::SCREENS, true) > 1 && $this->screen !== 'done',
            'verifyEmail' => EmailGate::appliesTo(auth()->user()) ? ['email' => auth()->user()->email, 'verified' => auth()->user()->fresh()->hasVerifiedEmail()] : null,
        ];
    }
}
