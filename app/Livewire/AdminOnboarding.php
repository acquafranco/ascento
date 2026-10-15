<?php

namespace App\Livewire;

use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\HelpCenter;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Help\HelpTopics;
use App\Support\Help\NavigationGuide;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Introducción del panel, botón «?» y recorrido del menú por categorías.
 *
 * - La introducción es breve (cómo orientarse, que las categorías del menú se
 *   despliegan y dónde está la ayuda; el dueño, además, los primeros pasos
 *   para configurar su empresa). Se abre sola la primera vez que entra el
 *   dueño o un administrativo.
 * - Terminada («Terminar») o descartada («Omitir» o cerrar con la X o Esc),
 *   no vuelve a abrirse sola. Abrirla después desde «?» no cambia ese estado.
 *   Nada se marca solo por cargar una página o por un error de JavaScript:
 *   solo esos botones llaman al servidor.
 * - El estado se guarda en SU usuario: nunca se recibe un id desde el
 *   navegador, así que no se puede leer ni tocar el de otra persona.
 * - El paso actual también se guarda en el servidor (users.onboarding_step):
 *   si sigue un enlace o recarga en medio de la introducción, se retoma.
 * - «Recorrer el menú» explica una categoría y cada opción que ESTA persona
 *   ve en el menú (NavigationGuide); abre la categoría si está cerrada y la
 *   vuelve a dejar como estaba al terminar. No guarda nada en el servidor.
 */
class AdminOnboarding extends Component
{
    /** Sección del panel donde se abrió (para la ayuda contextual). */
    #[Locked]
    public string $section = 'dashboard';

    /** Ruta de la pantalla donde se abrió (guías de «En esta pantalla»). */
    #[Locked]
    public string $routeName = '';

    /** Dirección de la pantalla, para «Volver a donde estabas» desde el Centro de ayuda. */
    #[Locked]
    public string $from = '';

    public function mount(?string $routeName = null): void
    {
        $this->routeName = $routeName ?? (string) request()->route()?->getName();
        $this->section = static::sectionFor($this->routeName);
        $this->from = '/'.ltrim(request()->path(), '/');
    }

    public const PAUSED_SESSION_KEY = 'onboarding.paused';

    #[Renderless]
    public function complete(): void
    {
        $this->user()->forceFill(['onboarding_completed_at' => now(), 'onboarding_step' => null])->save();
        session()->forget(self::PAUSED_SESSION_KEY);
    }

    #[Renderless]
    public function skip(): void
    {
        $this->user()->forceFill(['onboarding_skipped_at' => now(), 'onboarding_step' => null])->save();
        session()->forget(self::PAUSED_SESSION_KEY);
    }

    /** Guarda el paso en el que está (valor acotado: nunca uno inexistente). */
    #[Renderless]
    public function saveStep(int $index): void
    {
        $index = max(0, min($index, count(static::steps($this->user(), withMenu: false)) - 1));
        $user = $this->user();

        if ($user->onboarding_step !== $index) {
            $user->forceFill(['onboarding_step' => $index])->save();
        }
    }

    /**
     * Oculta la introducción solo por esta sesión (retoma en el mismo paso en
     * la próxima). La X y Esc ya no la usan: cerrar es descartar (skip).
     */
    #[Renderless]
    public function pause(): void
    {
        $this->user();
        session()->put(self::PAUSED_SESSION_KEY, true);
    }

    /**
     * Un "primer paso" de la tarjeta final: es el final de la guía, así que se
     * da por terminada y se va a esa pantalla. La URL sale de la lista del
     * servidor (nunca del navegador).
     */
    public function openChecklistItem(int $index)
    {
        $item = $this->checklist()[$index] ?? null;
        abort_unless($item !== null, 404);

        $this->complete();

        return $this->redirect($item['url']);
    }

    public function render()
    {
        $user = $this->user();
        $owner = $user->canUseOnboarding();

        return view('livewire.admin-onboarding', [
            'autoStart' => $user->shouldAutoStartOnboarding() && $user->company?->hasActiveAccess() && ! session(self::PAUSED_SESSION_KEY),
            'savedStep' => (int) $user->onboarding_step,
            'steps' => static::steps($user),
            'menu' => NavigationGuide::for($user),
            'checklist' => $owner ? $this->checklist() : [],
            'sectionHelp' => static::sectionHelp()[$this->section] ?? static::sectionHelp()['dashboard'],
            'screenGuides' => HelpTopics::screenGuides($this->routeName, $user),
            'helpCenterUrl' => HelpCenter::getUrl(['desde' => $this->from]),
            'from' => $this->from,
        ]);
    }

    protected function user(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->canUseHelpIntro(), 403);

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

        return [
            [
                'label' => 'Cargá los datos de tu empresa',
                'done' => filled($company->email) || filled($company->phone) || filled($company->address) || filled($company->logo),
                'url' => CompanySettings::getUrl(),
            ],
            [
                'label' => 'Agregá tus técnicos',
                'done' => User::where('company_id', $company->id)->whereNotIn('role', ['admin', 'client', 'staff'])->exists(),
                'url' => UserResource::getUrl('create'),
            ],
            [
                'label' => 'Creá tu primer cliente',
                'done' => Client::query()->exists(),
                'url' => ClientResource::getUrl('create'),
            ],
            [
                'label' => 'Agregá un edificio a ese cliente',
                'done' => Building::query()->exists(),
                'url' => BuildingResource::getUrl('create'),
            ],
            [
                'label' => 'Asigná un técnico a un edificio',
                // Antes: los ids de todos los edificios en un IN, en cada página del panel.
                'done' => Building::query()->whereExists(fn ($q) => $q->selectRaw('1')->from('building_user')->whereColumn('building_user.building_id', 'buildings.id'))->exists(),
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
     * Pasos de la introducción. `target` es la URL del ítem del menú lateral
     * que se señala; `group`, la categoría del menú; `help`, el botón «?»;
     * sin ninguno, tarjeta centrada. El dueño suma los primeros pasos para
     * configurar su empresa; un administrativo, solo cómo orientarse.
     *
     * @return list<array{key: string, title: string, body: string, target: ?string, group?: ?string, help?: bool, flow?: list<string>, list?: list<array{title: string, text: string}>}>
     */
    public static function steps(?User $user = null, bool $withMenu = true): array
    {
        $user ??= auth()->user();
        $owner = $user instanceof User && $user->canUseOnboarding();
        $menu = $withMenu && $user instanceof User ? NavigationGuide::for($user) : [];
        $groups = array_values(array_filter($menu, fn ($g) => $g['label'] !== null));

        $steps = [[
            'key' => 'welcome',
            'title' => 'Bienvenido a Ascento 👋',
            'body' => $owner
                ? 'En un minuto te mostramos cómo orientarte y por dónde empezar a configurar tu empresa. Podés cerrarla cuando quieras: la volvés a ver desde el botón «?».'
                : 'En un minuto te mostramos cómo orientarte. Podés cerrarla cuando quieras: la volvés a ver desde el botón «?».',
            'target' => null,
        ], [
            'key' => 'menu',
            'title' => 'El menú, por categorías',
            'body' => 'Las secciones están agrupadas en categorías. Tocá el nombre de una para desplegarla o cerrarla: la flecha indica si está abierta. Ascento recuerda en este navegador las que dejás abiertas.',
            'target' => null,
            'group' => $groups[0]['label'] ?? null,
            'list' => array_map(fn ($g) => ['title' => $g['title'], 'text' => $g['intro']], $groups),
        ]];

        if ($owner) {
            $steps = array_merge($steps, [
                [
                    'key' => 'company',
                    'title' => 'Primero, tu empresa',
                    'body' => 'En «Mi empresa» (arriba del menú) cargá los datos, el logo y el color de tu empresa: salen en los remitos, los presupuestos y el portal de tus clientes.',
                    'target' => CompanySettings::getUrl(),
                ],
                [
                    'key' => 'technicians',
                    'title' => 'Tus técnicos',
                    'body' => 'En «Administración → Técnicos» cargá a cada técnico con su correo y una contraseña. Con eso entran a Ascento desde el celular.',
                    'target' => UserResource::getUrl(),
                ],
                [
                    'key' => 'clients',
                    'title' => 'Tus clientes',
                    'body' => 'En «Gestión → Clientes» cargá los consorcios, administraciones o empresas para los que trabajás. Un cliente puede tener varios edificios.',
                    'target' => ClientResource::getUrl(),
                ],
                [
                    'key' => 'buildings',
                    'title' => 'Sus edificios',
                    'body' => 'En «Gestión → Edificios» cargá cada edificio con su dirección y sus ascensores y montacargas. Desde la lista, «Asignar empleado» define quién hace el mantenimiento y quién la inspección: con eso el técnico ya ve sus visitas.',
                    'target' => BuildingResource::getUrl(),
                    'flow' => ['Cliente', 'Edificios', 'Técnicos asignados'],
                ],
            ]);
        }

        $steps[] = [
            'key' => 'help',
            'title' => '¿Dudas? Tocá «?»',
            'body' => 'Desde este botón ves la ayuda de la pantalla en la que estás, recorrés cada categoría del menú paso a paso, volvés a ver esta guía y abrís el Centro de ayuda para buscar cualquier tarea.',
            'target' => null,
            'help' => true,
        ];

        $steps[] = [
            'key' => 'done',
            'title' => $owner ? '¡Listo! Ya conocés lo básico de Ascento.' : '¡Listo!',
            'body' => $owner
                ? 'Te recomendamos arrancar por los primeros pasos de abajo. Si te olvidás de algo, tocá «?».'
                : 'Abrí las categorías que uses y, si necesitás algo, tocá «?» o entrá a «Ayuda».',
            'target' => null,
        ];

        return $steps;
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
            'buildings' => ['title' => 'Edificios', 'body' => 'Cada edificio pertenece a un cliente. Cargá la dirección y cada ascensor y montacargas con su tracción: las cantidades salen de los equipos y, sin equipos, el edificio no recibe visitas. Con "Asignar empleado" elegís quién hace el mantenimiento (hasta dos técnicos) y quién la inspección (uno). Para varios edificios a la vez, usá el botón "Asignar técnicos" de arriba de la lista (o tildalos y elegilo en el menú de seleccionados): elegís agregar, reemplazar o conservar a los actuales y ves un resumen antes de confirmar. En "Frecuencia de visitas" definís cada cuánto se visita (mensual, quincenal o cada N meses), aparte de la frecuencia de cobro del servicio.'],
            'maintenances' => ['title' => 'Mantenimientos', 'body' => 'Los mantenimientos que hicieron tus técnicos, con su remito. Se generan solos cuando el técnico firma el remito desde el celular. Si el edificio es quincenal hay dos por mes, cada uno con su remito. Al abrir cada uno ves sus fotos de antes y después por zona (Pozo, Sala de máquinas, Techo de cabina, Cabina y puertas), si las habilitaste en «Mi empresa» (plan Empresa).'],
            'inspections' => ['title' => 'Inspecciones', 'body' => 'Las inspecciones realizadas, con su remito. Las carga el inspector asignado a cada edificio desde su celular, según la frecuencia de visitas del edificio. Al abrir cada una ves el reporte del inspector (observaciones y, si habilitaste las fotos de inspecciones en «Mi empresa», sus fotos) con «Ver reporte» (plan Empresa).'],
            'reports' => ['title' => 'Reportes', 'body' => 'Problemas que detectan los técnicos en un ascensor, con foto y prioridad. Cambiá el estado a "En revisión" o "Resuelto" a medida que los atendés. Los reportes de inspección se ven eligiendo el tipo «Inspección» en el filtro.'],
            'delivery_notes' => ['title' => 'Remitos', 'body' => 'El historial de todo el trabajo realizado (mantenimientos, inspecciones y órdenes de trabajo), con las firmas. Podés verlos y descargarlos; no se pueden borrar. Las fotos no van en el remito: se ven en cada mantenimiento y orden de trabajo.'],
            'work_orders' => ['title' => 'Órdenes de trabajo', 'body' => 'Trabajos puntuales: elegí edificio, ascensor, técnicos, tipo de trabajo, prioridad y detalle. El técnico la toma desde el celular y la cierra con el remito.'],
            'quotes' => ['title' => 'Presupuestos', 'body' => 'Presupuestos para tus clientes con ítems, total calculado, validez y estado (borrador, enviado, aprobado, rechazado o anulado; vencido si pasa la validez). Desde cada presupuesto lo podés enviar por WhatsApp o por mail.'],
            'map' => ['title' => 'Mapa', 'body' => 'Todos tus edificios en el mapa. Se ubican solos con la dirección que cargaste; si alguno no se pudo ubicar con precisión, aparece en "Edificios sin ubicar" para que corrijas la dirección o lo marques a mano. Tocá un punto para ver el cliente y abrir el edificio.'],
            'tasks' => ['title' => 'Tareas', 'body' => 'Pendientes de la oficina con responsable, vencimiento y recordatorio. En «Avisar a» elegís quién recibe el aviso.'],
            'contacts' => ['title' => 'Historial de contactos', 'body' => 'Cada llamado, visita o correo con un cliente, con su resultado, y quiénes llevan tiempo sin contacto.'],
            'public_qr' => ['title' => 'QR públicos', 'body' => 'Un código por edificio para que residentes y visitantes vean, sin cuenta, solo lo que elegís publicar.'],
            'parachute' => ['title' => 'Pruebas de paracaídas', 'body' => 'Periodicidad por ascensor o por cliente, registro de cada prueba y aviso de las próximas y vencidas.'],
            'repairs' => ['title' => 'Reparaciones', 'body' => 'Componentes enviados a un taller: estado, fechas, proveedor, costos e historial de cada cambio.'],
            'staff' => ['title' => 'Administrativos', 'body' => 'Personal de oficina con permisos por área: ver o gestionar. Los cambios rigen al instante.'],
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
            'resources.tareas.' => 'tasks',
            'resources.contactos.' => 'contacts',
            'pages.qr-publicos' => 'public_qr',
            'pages.paracaidas' => 'parachute',
            'resources.pruebas-paracaidas.' => 'parachute',
            'resources.reparaciones.' => 'repairs',
            'resources.administrativos.' => 'staff',
        ];

        foreach ($map as $needle => $section) {
            if (str_contains($routeName, $needle)) {
                return $section;
            }
        }

        return 'dashboard';
    }
}
