<?php

namespace App\Filament\Pages;

use App\Enums\PlanFeature;
use App\Models\Company;
use App\Rules\Cuit;
use App\Services\Onboarding\CompanySetup;
use App\Support\Access\Access;
use App\Support\Help\HelpTopics;
use App\Support\Plans\PlanUpsell;
use App\Support\Provinces;
use Filament\Forms;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class CompanySettings extends Page implements Forms\Contracts\HasForms
{
    use Forms\Concerns\InteractsWithForms;

    protected string $view = 'filament.pages.company-settings';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Mi empresa';

    // Primera del menú (arriba, sin categoría): el panel abre la primera opción,
    // así el dueño sigue entrando a «Mi empresa», como siempre.
    protected static ?int $navigationSort = -10;

    public ?array $data = [];

    public function mount(): void
    {

        $user = Auth::user();

        if ($user->isSuperAdmin()) {
            abort(403);
        }

        $company = $user->company;

        if (! $company) {
            abort(403);
        }

        $this->form->fill([
            'name' => $company->name,
            'business_name' => $company->business_name,
            'cuit' => $company->cuit,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'province' => $company->province,
            'city' => $company->city,
            'postal_code' => $company->postal_code,
            'tax_condition' => $company->tax_condition,
            'activity' => $company->activity,
            'gross_income_number' => $company->gross_income_number,
            'activity_started_at' => $company->activity_started_at,
            'bank_name' => $company->bank_name,
            'bank_cbu' => $company->bank_cbu,
            'bank_alias' => $company->bank_alias,
            'logo' => $company->logo,
            'primary_color' => $company->primary_color,
            'photos_maintenance' => (bool) $company->photos_maintenance,
            'photos_inspection' => (bool) $company->photos_inspection,
            'photos_work_order' => (bool) $company->photos_work_order,
            'qr_reports_enabled' => (bool) $company->qr_reports_enabled,
            // Los datos de WhatsApp (incluido el access token) NO se cargan
            // acá: todo lo que entra al form viaja al navegador en el estado
            // de Livewire.
        ]);
    }

    public function getFormStatePath(): string
    {
        return 'data';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([

                Section::make('Empresa')
                    ->description('Información principal de la empresa.')
                    ->icon('heroicon-o-building-office-2')
                    ->columns(12)
                    ->schema([

                        static::logoField()->columnSpan(3),

                        static::nameField()->columnSpan(4),

                        TextInput::make('business_name')
                            ->label('Razón social')
                            ->maxLength(255)
                            ->placeholder('Empresa Ascensores S.R.L.')
                            ->prefixIcon('heroicon-o-building-office')
                            ->columnSpan(5),

                        TextInput::make('cuit')
                            ->label('CUIT')
                            ->maxLength(20)
                            ->rule(new Cuit)
                            ->dehydrateStateUsing(fn (?string $state) => Cuit::format($state))
                            ->placeholder('30-12345678-9')
                            ->prefixIcon('heroicon-o-identification')
                            ->columnSpan(4),

                        static::phoneField()->columnSpan(4),

                        static::emailField()->columnSpan(4),

                        TextInput::make('address')
                            ->label('Domicilio')
                            ->maxLength(255)
                            ->placeholder('Av. Corrientes 1234')
                            ->prefixIcon('heroicon-o-map-pin')
                            // Con la barra lateral, a 1024 px dos columnas de 12 no alcanzan
                            // para un selector: la fila se parte en dos hasta xl.
                            ->columnSpan(['lg' => 12, 'xl' => 6]),

                        Forms\Components\Select::make('province')
                            ->label('Provincia')
                            ->options(Provinces::options())
                            ->searchable()
                            ->columnSpan(['lg' => 4, 'xl' => 2]),

                        TextInput::make('city')
                            ->label('Localidad')
                            ->maxLength(255)
                            ->columnSpan(['lg' => 4, 'xl' => 2]),

                        TextInput::make('postal_code')
                            ->label('Código postal')
                            ->maxLength(10)
                            ->columnSpan(['lg' => 4, 'xl' => 2]),
                    ]),

                // Opcional: para presupuestos/remitos y una futura integración
                // fiscal. Ascento NO factura.
                Section::make('Datos fiscales (opcional)')
                    ->description('Completalos cuando quieras. Ascento no emite facturas: quedan guardados para tus documentos y para una futura integración.')
                    ->icon('heroicon-o-document-text')
                    ->collapsible()
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        Forms\Components\Select::make('tax_condition')
                            ->label('Condición frente al IVA')
                            ->options([
                                'responsable_inscripto' => 'Responsable inscripto',
                                'monotributo' => 'Monotributo',
                                'exento' => 'Exento',
                                'no_alcanzado' => 'No alcanzado',
                            ]),
                        TextInput::make('activity')->label('Actividad')->maxLength(255)->placeholder('Mantenimiento de ascensores'),
                        TextInput::make('gross_income_number')->label('Ingresos Brutos')->maxLength(30),
                        Forms\Components\DatePicker::make('activity_started_at')->label('Inicio de actividades')->native(false)->displayFormat('d/m/Y'),
                    ]),

                Section::make('Datos bancarios (opcional)')
                    ->description('Para mostrarlos en presupuestos y cobros si lo necesitás.')
                    ->icon('heroicon-o-banknotes')
                    ->collapsible()
                    ->collapsed()
                    ->columns(3)
                    ->schema([
                        TextInput::make('bank_name')->label('Banco')->maxLength(255),
                        TextInput::make('bank_cbu')->label('CBU / CVU')->maxLength(22)->regex('/^\d{22}$/')
                            ->validationMessages(['regex' => 'El CBU tiene 22 números.']),
                        TextInput::make('bank_alias')->label('Alias')->maxLength(40)->regex('/^[A-Za-z0-9.\-]{6,20}$/')
                            ->validationMessages(['regex' => 'El alias tiene entre 6 y 20 letras, números, puntos o guiones.']),
                    ]),

                // Documentación fotográfica por tipo de trabajo (apagada por defecto).
                Section::make('Fotos de los trabajos')
                    ->description(fn () => $this->hasWorkPhotos()
                        ? 'Fotos de antes y después de mantenimientos y órdenes, y fotos del reporte de inspección. Vienen apagadas. Cada interruptor se guarda al tocarlo. Son opcionales para el técnico y nunca las ve el cliente. Apagar un tipo solo impide cargar fotos nuevas: las ya cargadas se conservan y se siguen viendo.'
                        : 'Disponible en el plan Empresa: fotos de antes y después de mantenimientos y órdenes, y reportes de inspección con fotos. Si ya se habían cargado, se conservan y vuelven a verse al pasar a ese plan.')
                    ->icon('heroicon-o-camera')
                    ->columns(3)
                    ->schema([
                        // Se guardan al tocarlos (antes había que bajar hasta
                        // «Guardar cambios»; si no, el cambio se perdía al salir).
                        ...collect(['photos_maintenance' => 'Mantenimientos', 'photos_inspection' => 'Inspecciones', 'photos_work_order' => 'Órdenes de trabajo'])
                            ->map(fn (string $label, string $column) => Forms\Components\Toggle::make($column)->label($label)
                                // Solo verdadero o falso (también al guardar todo el formulario).
                                ->rule('boolean')
                                // Sin el plan Empresa: se ven como están, sin poder cambiarlos (no se borran).
                                ->disabled(fn () => ! $this->hasWorkPhotos())
                                ->live()
                                ->afterStateUpdated(fn ($livewire, $state) => $livewire->savePhotoSetting($column, $state)))
                            ->values()->all(),
                    ]),

                // Reportes de problemas desde el QR (Ascento Conecta; Profesional y Empresa). Encendido por defecto.
                Section::make('Reportes de problemas desde el QR')
                    ->description(fn () => $this->hasQrReports()
                        ? 'La página del QR de cada edificio publicado muestra «Reportar un problema»: quien usa el ascensor cuenta qué notó (puertas, ruidos, luces, daños) y opcionalmente adjunta una foto. Llega a «Reclamos de clientes» y se avisa a tu empresa y a los técnicos asignados al edificio. No es un servicio de emergencias ni un pedido de rescate. Se guarda al tocarlo.'
                        : 'Disponible en los planes Profesional y Empresa: quien usa el ascensor reporta un problema desde el QR del edificio. Los reportes ya registrados se conservan.')
                    ->icon('heroicon-o-flag')
                    ->schema([
                        Forms\Components\Toggle::make('qr_reports_enabled')
                            ->label('Recibir reportes de problemas desde el QR público')
                            ->rule('boolean')
                            ->dehydrated(false) // nunca viaja en «Guardar cambios»: solo con saveQrReportsSetting
                            ->disabled(fn () => ! $this->hasQrReports())
                            ->live()
                            ->afterStateUpdated(fn ($livewire, $state) => $livewire->saveQrReportsSetting($state)),
                    ]),

                Section::make('Apariencia')
                    ->description('Personalizá la identidad visual del sistema.')
                    ->icon('heroicon-o-paint-brush')
                    ->columns(2)
                    ->schema([
                        static::colorField(),
                    ]),
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | CAMPOS COMPARTIDOS con la configuración inicial guiada (CompanySetupWizard)
    |--------------------------------------------------------------------------
    */

    public static function logoField(): FileUpload
    {
        return FileUpload::make('logo')
            ->label('Logo')
            ->image()
            ->imageEditor()
            ->avatar()
            ->disk('public')
            ->directory('companies/logos')
            ->visibility('public')
            ->maxSize(2048)
            ->acceptedFileTypes([
                'image/png',
                'image/jpeg',
                'image/webp',
            ]);
    }

    public static function nameField(): TextInput
    {
        return TextInput::make('name')
            ->label('Nombre interno')
            ->maxLength(255)
            ->placeholder('Empresa Ascensores')
            ->prefixIcon('heroicon-o-tag')
            ->required();
    }

    public static function phoneField(): TextInput
    {
        return TextInput::make('phone')
            ->label('Teléfono')
            ->maxLength(50)
            ->tel()
            ->placeholder('+54 11 1234-5678')
            ->prefixIcon('heroicon-o-phone');
    }

    public static function emailField(): TextInput
    {
        return TextInput::make('email')
            ->label('Email')
            ->maxLength(255)
            ->email()
            ->placeholder('contacto@empresa.com')
            ->prefixIcon('heroicon-o-envelope');
    }

    public static function colorField(): ColorPicker
    {
        return ColorPicker::make('primary_color')
            ->label('Color principal')
            ->default('#2563eb');
    }

    private function hasQrReports(): bool
    {
        return (bool) Auth::user()?->company?->plan()->allows(PlanFeature::QrReports);
    }

    /**
     * Interruptor de reportes desde el QR, guardado en el momento: solo el dueño
     * de SU empresa, solo verdadero o falso y solo si el plan lo incluye.
     */
    public function saveQrReportsSetting(mixed $on): void
    {
        $user = Auth::user();
        abort_if($user->isSuperAdmin() || ! Access::isOwner($user) || ! $user->company, 403);
        $company = $user->company;

        if (! $company->plan()->allows(PlanFeature::QrReports)) {
            $this->data['qr_reports_enabled'] = (bool) $company->qr_reports_enabled;
            PlanUpsell::featureNotification($company, PlanFeature::QrReports)->send();

            return;
        }

        if (! is_bool($on)) {
            $this->data['qr_reports_enabled'] = (bool) $company->qr_reports_enabled;
            Notification::make()->title('No se guardó el cambio')->body('Valor inválido para el interruptor.')->danger()->send();

            return;
        }

        $company->forceFill(['qr_reports_enabled' => $on])->save();

        Notification::make()
            ->title($on ? 'Reportes desde el QR activados' : 'Reportes desde el QR desactivados')
            ->body($on ? '«Reportar un problema» aparece en el QR de los edificios publicados.' : 'El botón ya no aparece y no se registran reportes nuevos. Los registrados se conservan.')
            ->success()
            ->send();
    }

    private function hasWorkPhotos(): bool
    {
        return (bool) Auth::user()?->company?->hasWorkPhotos();
    }

    /**
     * Un interruptor de fotos, guardado en el momento: solo el admin de SU
     * empresa, solo esas tres columnas y solo verdadero o falso (un valor
     * manipulado no se guarda y la pantalla vuelve a lo guardado).
     */
    public function savePhotoSetting(string $column, mixed $on): void
    {
        $user = Auth::user();

        abort_unless(in_array($column, Company::PHOTO_SETTINGS, true), 404);
        abort_if($user->isSuperAdmin() || ! $user->isAdmin() || ! $user->company, 403);

        if (! $user->company->hasWorkPhotos()) {
            $this->data[$column] = (bool) $user->company->{$column};
            PlanUpsell::featureNotification($user->company, PlanFeature::WorkPhotos)->send();

            return;
        }

        if (! is_bool($on)) {
            $this->data[$column] = (bool) $user->company->{$column};

            Notification::make()->title('No se guardó el cambio')->body('Valor inválido para el interruptor.')->danger()->send();

            return;
        }

        $user->company->forceFill([$column => $on])->save();

        $label = ['photos_maintenance' => 'mantenimientos', 'photos_inspection' => 'inspecciones', 'photos_work_order' => 'órdenes de trabajo'][$column];

        Notification::make()
            ->title('Fotos de '.$label.': '.($on ? 'activadas' : 'desactivadas'))
            ->body($on ? 'El técnico ya ve «Tomar foto» y «Elegir de la galería» en esos trabajos.' : 'No se cargan fotos nuevas de ese tipo; las ya cargadas se conservan.')
            ->success()
            ->send();
    }

    public function save(): void
    {
        $user = Auth::user();

        if ($user->isSuperAdmin() || ! Access::isOwner($user)) {
            abort(403);
        }

        if (! $user->company) {
            abort(403);
        }

        // getState() solo devuelve los campos del formulario (nada de
        // suscripción ni de integraciones viejas).
        $data = $this->form->getState();

        $user->company->update($data);

        // Configuración inicial guiada: el paso de los datos de la empresa queda hecho.
        CompanySetup::companySaved($user);

        Notification::make()
            ->title('Empresa actualizada')
            ->success()
            ->send();
    }

    /*
    |--------------------------------------------------------------------------
    | AYUDA: volver a ver una ayuda puntual o la guía de bienvenida
    |--------------------------------------------------------------------------
    */

    /** @return array<string, array{title: string, seen: bool}> */
    public function helpTopics(): array
    {
        $seen = Auth::user()->helpDismissals()->pluck('key')->flip();

        return collect(HelpTopics::all())
            ->map(fn ($topic, $key) => ['title' => $topic['title'], 'seen' => $seen->has($key)])
            ->all();
    }

    public function resetHelp(string $key): void
    {
        abort_unless(HelpTopics::exists($key), 404);

        Auth::user()->resetHelp($key);

        Notification::make()->title('Listo: la ayuda vuelve a aparecer en esa pantalla.')->success()->send();
    }

    public function resetAllHelp(): void
    {
        Auth::user()->helpDismissals()->delete();

        Notification::make()->title('Listo: todas las ayudas vuelven a aparecer.')->success()->send();
    }

    public function resetWelcomeGuide(): void
    {
        Auth::user()->forceFill(['onboarding_completed_at' => null, 'onboarding_skipped_at' => null])->save();

        Notification::make()->title('La guía de bienvenida se abre la próxima vez que entres a una pantalla.')->success()->send();
    }

    public function getTitle(): string
    {
        return Auth::user()?->company?->name ?? 'Mi empresa';
    }

    public function getHeading(): string
    {
        return Auth::user()?->company?->name ?? 'Mi empresa';
    }

    public function getSubheading(): ?string
    {
        return 'Configuración de la empresa';
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * «Mi empresa» es del dueño (Etapa 6). Hasta ahora solo admins y el
     * SuperAdmin entraban al panel, así que para ellos no cambia nada; un
     * administrativo nunca la abre, aunque tenga todos los permisos.
     */
    public static function canAccess(): bool
    {
        return Access::isOwner(auth()->user());
    }
}
