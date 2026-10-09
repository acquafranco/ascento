<?php

namespace App\Livewire;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Subscription;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Guía de bienvenida + ayuda contextual del panel /admin.
 *
 * - Se abre sola la primera vez que entra un admin de empresa.
 * - El estado (terminada / omitida) se guarda en SU usuario: nunca se
 *   recibe un id desde el navegador, así que no se puede leer ni tocar el
 *   estado de otro admin ni de otra empresa.
 * - Los pasos señalan los ítems reales del menú lateral (URLs generadas
 *   desde los Resources/Pages de Filament).
 */
class AdminOnboarding extends Component
{
    /** Sección del panel donde se abrió (para la ayuda contextual). */
    #[Locked]
    public string $section = 'dashboard';

    public function mount(?string $routeName = null): void
    {
        $this->section = static::sectionFor($routeName ?? (string) request()->route()?->getName());
    }

    public function complete(): void
    {
        $this->user()->forceFill(['onboarding_completed_at' => now()])->save();
    }

    public function skip(): void
    {
        $this->user()->forceFill(['onboarding_skipped_at' => now()])->save();
    }

    public function render()
    {
        $user = $this->user();

        return view('livewire.admin-onboarding', [
            'autoStart' => $user->shouldAutoStartOnboarding() && $user->company?->hasActiveAccess(),
            'steps' => static::steps(),
            'checklist' => $this->checklist(),
            'sectionHelp' => static::sectionHelp()[$this->section] ?? static::sectionHelp()['dashboard'],
            'userKey' => 'ascento-onboarding-'.$user->id,
        ]);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->canUseOnboarding(), 403);

        return $user;
    }

    /**
     * "Primero esto, después esto": progreso real de la empresa del admin.
     * Las consultas pasan por el scope de empresa (BelongsToCompany).
     *
     * @return list<array{label: string, done: bool, url: string}>
     */
    protected function checklist(): array
    {
        $company = $this->user()->company;

        $companyBuildingIds = Building::query()->pluck('id');

        return [
            [
                'label' => 'Cargá los datos de tu empresa',
                'done' => filled($company->email) || filled($company->phone) || filled($company->address) || filled($company->logo),
                'url' => CompanySettings::getUrl(),
            ],
            [
                'label' => 'Agregá tus técnicos',
                'done' => User::where('company_id', $company->id)->whereNotIn('role', ['admin', 'client'])->exists(),
                'url' => UserResource::getUrl('create'),
            ],
            [
                'label' => 'Creá tu primer cliente',
                'done' => Client::query()->exists(),
                'url' => ClientResource::getUrl('create'),
            ],
            [
                'label' => 'Agregá un edificio a ese cliente',
                'done' => $companyBuildingIds->isNotEmpty(),
                'url' => BuildingResource::getUrl('create'),
            ],
            [
                'label' => 'Asigná un técnico a un edificio',
                'done' => $companyBuildingIds->isNotEmpty()
                    && DB::table('building_user')->whereIn('building_id', $companyBuildingIds)->exists(),
                'url' => BuildingResource::getUrl('index'),
            ],
            [
                'label' => 'Creá tu primera orden de trabajo (opcional)',
                'done' => WorkOrder::query()->exists(),
                'url' => WorkOrderResource::getUrl('create'),
            ],
        ];
    }

    /**
     * Pasos del recorrido. `target` es la URL del ítem del menú lateral que
     * se resalta; null = tarjeta centrada (bienvenida y cierre).
     *
     * @return list<array{key: string, title: string, body: string, target: ?string, flow?: list<string>}>
     */
    public static function steps(): array
    {
        return [
            [
                'key' => 'welcome',
                'title' => 'Bienvenido a Ascento 👋',
                'body' => 'Te vamos a mostrar rápidamente cómo configurar tu empresa y empezar a trabajar. Son unos pocos pasos y podés cerrarla cuando quieras.',
                'target' => null,
            ],
            [
                'key' => 'company',
                'title' => 'Mi empresa',
                'body' => 'Empezá por acá. Cargá el nombre de tu empresa, la razón social, el CUIT, los datos de contacto, el logo y el color que la identifica dentro de Ascento.',
                'target' => CompanySettings::getUrl(),
            ],
            [
                'key' => 'dashboard',
                'title' => 'Inicio',
                'body' => 'Es tu resumen del día y se actualiza solo. Ves las órdenes de trabajo pendientes, en proceso y completadas hoy, los remitos del día, los presupuestos que esperan respuesta, cuántos edificios y máquinas tenés, y qué técnicos están ocupados o disponibles.',
                'target' => Dashboard::getUrl(),
            ],
            [
                'key' => 'technicians',
                'title' => 'Técnicos',
                'body' => 'Ahora cargá a las personas que trabajan en la calle: nombre, email, número de teléfono y una contraseña. Con ese email y esa contraseña cada técnico entra a Ascento desde su celular. Si hace mantenimiento o inspección lo definís después, al asignarlo a un edificio.',
                'target' => UserResource::getUrl(),
            ],
            [
                'key' => 'clients',
                'title' => 'Clientes',
                'body' => 'Son los consorcios, empresas, hospitales o particulares para los que trabajás. Un cliente puede tener uno o varios edificios. Entrá a Clientes y tocá "Crear Cliente" para cargar el primero.',
                'target' => ClientResource::getUrl(),
            ],
            [
                'key' => 'buildings',
                'title' => 'Edificios',
                'body' => 'Después de crear un cliente, cargá sus edificios: dirección, contacto y cuántos ascensores y montacargas tiene. Desde la lista, con "Asignar empleado", elegís quién hace el mantenimiento (hasta dos técnicos) y quién la inspección (uno por edificio).',
                'target' => BuildingResource::getUrl(),
                'flow' => ['Cliente', 'Edificios', 'Técnicos asignados'],
            ],
            [
                'key' => 'maintenances',
                'title' => 'Mantenimientos e inspecciones',
                'body' => 'Con todo cargado, tus técnicos ya pueden trabajar. Desde el celular ven los edificios que tienen asignados y, al terminar el mantenimiento o la inspección del mes, firman el remito. Acá ves los mantenimientos realizados y, justo debajo, las inspecciones.',
                'target' => MaintenanceResource::getUrl(),
            ],
            [
                'key' => 'reports',
                'title' => 'Reportes',
                'body' => 'Cuando un técnico detecta un problema en un ascensor, lo informa desde el celular con una foto y una prioridad. Te llega un aviso y acá lo seguís: pendiente, en revisión o resuelto.',
                'target' => ReportResource::getUrl(),
            ],
            [
                'key' => 'delivery_notes',
                'title' => 'Remitos',
                'body' => 'Es el historial de todo el trabajo hecho: remitos de mantenimiento, de inspección y de órdenes de trabajo, con la firma del técnico (y la del cliente, si firmó). Los podés ver y descargar cuando quieras. No se pueden borrar, así tu historial queda siempre completo.',
                'target' => DeliveryNoteResource::getUrl(),
            ],
            [
                'key' => 'work_orders',
                'title' => 'Órdenes de trabajo',
                'body' => 'Para trabajos puntuales (un reclamo, una instalación, una modernización) creá una orden: elegí el edificio y el ascensor, quién lo va a hacer (uno o varios técnicos), el tipo de trabajo, la prioridad y el detalle. El técnico la ve en su celular, la toma y la cierra con el remito.',
                'target' => WorkOrderResource::getUrl(),
            ],
            [
                'key' => 'quotes',
                'title' => 'Presupuestos',
                'body' => 'Armá presupuestos para tus clientes: edificio, equipo, ítems con cantidad y precio (el total se calcula solo) y validez. Les vas cambiando el estado (borrador, enviado, aprobado, rechazado o anulado) y se los podés mandar por WhatsApp o por mail con un link para verlo online.',
                'target' => QuoteResource::getUrl(),
            ],
            [
                'key' => 'subscription',
                'title' => 'Mi suscripción',
                'body' => 'Acá ves tu plan, cuánto usaste de cada límite (edificios, clientes, técnicos y reportes) y podés elegir o cambiar de plan con Mercado Pago.',
                'target' => Subscription::getUrl(),
            ],
            [
                'key' => 'done',
                'title' => '¡Listo! Ya conocés lo básico de Ascento.',
                'body' => 'Te recomendamos arrancar por los primeros pasos de abajo. Si te olvidás de algo, tocá el botón "?" de arriba a la derecha: ahí podés volver a ver esta guía y la ayuda de cada sección.',
                'target' => null,
            ],
        ];
    }

    /**
     * Ayuda corta de cada sección, para el botón "?".
     *
     * @return array<string, array{title: string, body: string}>
     */
    public static function sectionHelp(): array
    {
        return [
            'dashboard' => ['title' => 'Inicio', 'body' => 'Resumen del día que se actualiza solo: órdenes de trabajo pendientes, en proceso y completadas, remitos de hoy, presupuestos pendientes, edificios, máquinas y técnicos ocupados o disponibles.'],
            'company' => ['title' => 'Mi empresa', 'body' => 'Los datos de tu empresa (nombre, razón social, CUIT, contacto), el logo y el color con el que aparece en Ascento. Acordate de tocar "Guardar".'],
            'technicians' => ['title' => 'Técnicos', 'body' => 'Las personas que trabajan en la calle. Cada una entra a Ascento desde el celular con su email y su contraseña. Las órdenes de trabajo le llegan como aviso en el celular (notificaciones o Telegram). Si alguien deja la empresa, desactivalo: su historial se conserva.'],
            'clients' => ['title' => 'Clientes', 'body' => 'Consorcios, empresas, hospitales o particulares para los que trabajás. Primero creá el cliente; después vas a poder agregar sus edificios desde Edificios. En "Acceso al portal" (planes Profesional y Empresa) invitás por email a las personas del cliente para que vean lo que compartas (remitos, reportes, presupuestos y documentos marcados "En portal").'],
            'buildings' => ['title' => 'Edificios', 'body' => 'Cada edificio pertenece a un cliente. Cargá la dirección y cuántos ascensores y montacargas tiene. Con "Asignar empleado" elegís quién hace el mantenimiento (hasta dos técnicos) y quién la inspección (uno).'],
            'maintenances' => ['title' => 'Mantenimientos', 'body' => 'Los mantenimientos que hicieron tus técnicos, con su remito. Se generan solos cuando el técnico firma el remito desde el celular.'],
            'inspections' => ['title' => 'Inspecciones', 'body' => 'Las inspecciones realizadas, con su remito. Las carga el inspector asignado a cada edificio desde su celular.'],
            'reports' => ['title' => 'Reportes', 'body' => 'Problemas que detectan los técnicos en un ascensor, con foto y prioridad. Cambiá el estado a "En revisión" o "Resuelto" a medida que los atendés.'],
            'delivery_notes' => ['title' => 'Remitos', 'body' => 'El historial de todo el trabajo realizado (mantenimientos, inspecciones y órdenes de trabajo), con las firmas. Podés verlos y descargarlos; no se pueden borrar.'],
            'work_orders' => ['title' => 'Órdenes de trabajo', 'body' => 'Trabajos puntuales: elegí edificio, ascensor, técnicos, tipo de trabajo, prioridad y detalle. El técnico la toma desde el celular y la cierra con el remito.'],
            'quotes' => ['title' => 'Presupuestos', 'body' => 'Presupuestos para tus clientes con ítems, total calculado, validez y estado (borrador, enviado, aprobado, rechazado o anulado; vencido si pasa la validez). Desde cada presupuesto lo podés enviar por WhatsApp o por mail.'],
            'map' => ['title' => 'Mapa', 'body' => 'Todos tus edificios en el mapa. Se ubican solos con la dirección que cargaste; si alguno no se pudo ubicar con precisión, aparece en "Edificios sin ubicar" para que corrijas la dirección o lo marques a mano. Tocá un punto para ver el cliente y abrir el edificio.'],
            'subscription' => ['title' => 'Mi suscripción', 'body' => 'Tu plan (Inicial, Profesional o Empresa), cuánto usaste de cada límite y cómo cambiar de plan o suscribirte con Mercado Pago.'],
        ];
    }

    /**
     * Traduce el nombre de la ruta de Filament a una sección de ayuda.
     */
    public static function sectionFor(string $routeName): string
    {
        $map = [
            'pages.company-settings' => 'company',
            'pages.subscription' => 'subscription',
            'pages.mapa' => 'map',
            'resources.users.' => 'technicians',
            'resources.clients.' => 'clients',
            'resources.buildings.' => 'buildings',
            'resources.maintenances.' => 'maintenances',
            'resources.inspections.' => 'inspections',
            'resources.reports.' => 'reports',
            'resources.delivery-notes.' => 'delivery_notes',
            'resources.work-orders.' => 'work_orders',
            'resources.quotes.' => 'quotes',
        ];

        foreach ($map as $needle => $section) {
            if (str_contains($routeName, $needle)) {
                return $section;
            }
        }

        return 'dashboard';
    }
}
