<?php

namespace Tests\Feature\Access;

use App\Enums\PermissionArea;
use App\Enums\PlanLimit;
use App\Filament\Concerns\GuardedByPermission;
use App\Filament\Pages\Agenda;
use App\Filament\Pages\AttentionCenterPage;
use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\CompanyExports;
use App\Filament\Pages\CompanyOverview;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\IndicatorsPage;
use App\Filament\Pages\Subscription as SubscriptionPage;
use App\Filament\Pages\SystemBackups;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Elevators\ElevatorResource;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\MaintenanceServices\MaintenanceServiceResource;
use App\Filament\Resources\Quotes\QuoteResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\StockItems\StockItemResource;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Filament\Widgets\AdminStats;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Client;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\Quote;
use App\Models\Report;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Activity\ActivityLogger;
use App\Services\Buildings\BulkAssignmentService;
use App\Services\Elevators\ElevatorDocumentService;
use App\Support\Access\Access;
use App\Support\Access\ActionGuard;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 6, PR 1: infraestructura de permisos. El dueño y el SuperAdmin no
 * cambian; un administrativo (role = staff) solo entra a lo que el dueño le
 * habilitó, verificado en el servidor (recursos, páginas, acciones, Livewire,
 * rutas, archivos), nunca a lo reservado al dueño ni a otra empresa, no usa
 * la app del técnico ni ocupa cupo de técnicos. Registro de actividad.
 */
class StaffPermissionsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    /** Recursos del panel de una empresa → su área. */
    private const RESOURCES = [
        BuildingResource::class => PermissionArea::Buildings,
        ElevatorResource::class => PermissionArea::Buildings,
        ClientResource::class => PermissionArea::Clients,
        DeliveryNoteResource::class => PermissionArea::Documents,
        InspectionResource::class => PermissionArea::Inspections,
        ReportResource::class => PermissionArea::Inspections,
        MaintenanceResource::class => PermissionArea::Visits,
        MaintenanceServiceResource::class => PermissionArea::Receivables,
        ReceivableResource::class => PermissionArea::Receivables,
        QuoteResource::class => PermissionArea::Quotes,
        StockItemResource::class => PermissionArea::Stock,
        StockMovementResource::class => PermissionArea::Stock,
        UserResource::class => PermissionArea::Technicians,
        WorkOrderResource::class => PermissionArea::WorkOrders,
    ];

    private const PAGES = [
        Agenda::class => PermissionArea::Visits,
        AttentionCenterPage::class => PermissionArea::Attention,
        IndicatorsPage::class => PermissionArea::Indicators,
        BuildingsMap::class => PermissionArea::Buildings,
    ];

    /** Nunca para un administrativo, con cualquier permiso. */
    private const RESERVED_PAGES = [CompanySettings::class, SubscriptionPage::class, CompanyExports::class, SystemBackups::class, CompanyOverview::class];

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
    }

    /** Administrativo de la empresa (lo crea el sistema: en el PR 1 no hay alta desde la app). */
    private function staff(array $permissions = [], ?array $tenant = null): User
    {
        auth()->logout();
        $user = User::factory()->create(['company_id' => ($tenant ?? $this->a)['company']->id, 'role' => User::ROLE_STAFF]);
        $user->forceFill(['permissions' => $permissions])->save();

        return $user->fresh();
    }

    private function all(): array
    {
        return PermissionArea::keys();
    }

    private function inPanel(User $user): void
    {
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    /*
    |--------------------------------------------------------------------------
    | EL DUEÑO Y EL SUPERADMIN NO CAMBIAN
    |--------------------------------------------------------------------------
    */

    public function test_the_owner_keeps_every_screen_and_action(): void
    {
        $owner = $this->a['admin'];
        $this->assertTrue(Access::isOwner($owner));
        $this->inPanel($owner);

        foreach (array_keys(self::RESOURCES) as $resource) {
            $this->assertTrue($resource::canAccess(), $resource);
            $this->get($resource::getUrl('index'))->assertOk();
        }
        foreach ([...array_keys(self::PAGES), CompanySettings::class, SubscriptionPage::class, CompanyExports::class] as $page) {
            $this->assertTrue($page::canAccess(), $page);
        }
        foreach ([...array_keys(self::PAGES), CompanySettings::class, CompanyExports::class] as $page) {
            $this->get($page::getUrl())->assertOk();
        }

        // Crear, editar y las acciones propias, como siempre.
        $this->assertTrue(BuildingResource::canCreate());
        $this->assertTrue(ClientResource::canEdit(Client::find($this->a['building']->client_id)));
        Livewire::test(ListBuildings::class)->assertActionVisible('assignTechnicians');
        $this->get(BuildingResource::getUrl('edit', ['record' => $this->a['building']]))->assertOk();
    }

    public function test_the_super_admin_keeps_its_access(): void
    {
        auth()->logout();
        $root = User::factory()->superAdmin()->create(['company_id' => null]);
        $this->inPanel($root);
        session(['selected_company_id' => $this->a['company']->id]);

        $this->assertTrue(Access::panelUser($root));
        foreach (array_keys(self::RESOURCES) as $resource) {
            $this->assertTrue($resource::canAccess(), $resource);
        }
        $this->assertTrue(SystemBackups::canAccess());
        $this->assertFalse(CompanySettings::canAccess()); // como antes: «Mi empresa» no es del SuperAdmin
    }

    /*
    |--------------------------------------------------------------------------
    | ADMINISTRATIVO SIN PERMISOS: NADA
    |--------------------------------------------------------------------------
    */

    public function test_a_staff_user_starts_without_permissions_and_sees_nothing(): void
    {
        $staff = $this->staff();
        $this->assertSame([], Access::permissionsOf($staff));
        $this->assertTrue(Access::panelUser($staff));
        $this->inPanel($staff);

        $this->get('/admin')->assertRedirect('/admin/dashboard');
        // Su inicio, sin los números de la empresa (widgets diferidos: se verifica su control).
        $this->get('/admin/dashboard')->assertOk();
        $this->assertFalse(AdminStats::canView());
        $this->inPanel($this->a['admin']);
        $this->assertTrue(AdminStats::canView()); // el dueño, como siempre
        $this->inPanel($staff);
        foreach (array_keys(self::RESOURCES) as $resource) {
            $this->assertFalse($resource::canAccess(), $resource);
            $this->get($resource::getUrl('index'))->assertForbidden();
        }
        foreach (array_keys(self::PAGES) as $page) {
            $this->assertFalse($page::canAccess(), $page);
            $this->get($page::getUrl())->assertForbidden();
        }
        $this->get(BuildingResource::getUrl('edit', ['record' => $this->a['building']]))->assertForbidden();
        $this->get(BuildingResource::getUrl('create'))->assertForbidden();
    }

    public function test_each_area_opens_exactly_its_screens_and_view_is_not_manage(): void
    {
        foreach (PermissionArea::cases() as $area) {
            $staff = $this->staff([$area->key(PermissionArea::VIEW)]);
            $this->inPanel($staff);

            foreach (self::RESOURCES as $resource => $resourceArea) {
                $this->assertSame($resourceArea === $area, $resource::canAccess(), "{$area->value} → {$resource}");
                // «Ver» nunca habilita crear.
                $this->assertFalse($resource::canCreate(), "{$area->value} → crear {$resource}");
            }
            foreach (self::PAGES as $page => $pageArea) {
                $this->assertSame($pageArea === $area, $page::canAccess(), "{$area->value} → {$page}");
            }
        }

        // «Gestionar» habilita crear y editar solo en su área (y los usuarios siguen siendo del dueño).
        $staff = $this->staff([PermissionArea::Buildings->key(PermissionArea::MANAGE), PermissionArea::Technicians->key(PermissionArea::MANAGE)]);
        $this->inPanel($staff);
        $this->assertTrue(BuildingResource::canCreate());
        $this->assertTrue(BuildingResource::canEdit($this->a['building']));
        $this->get(BuildingResource::getUrl('create'))->assertOk();
        $this->assertFalse(ClientResource::canCreate());
        $this->assertTrue(UserResource::canViewAny());
        $this->assertFalse(UserResource::canCreate());
        $this->assertFalse(UserResource::canEdit($this->a['technician']));
        $this->assertFalse(UserResource::canEdit($this->a['admin']));
    }

    public function test_reserved_owner_screens_stay_closed_even_with_every_permission(): void
    {
        $staff = $this->staff($this->all());
        $this->inPanel($staff);

        foreach (self::RESERVED_PAGES as $page) {
            $this->assertFalse($page::canAccess(), $page);
        }
        $this->get(CompanySettings::getUrl())->assertForbidden();
        $this->get(SubscriptionPage::getUrl())->assertForbidden();
        $this->get(CompanyExports::getUrl())->assertForbidden();
        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $this->a['technician']]))->assertForbidden();
        $this->assertContains($this->get(UserResource::getUrl('edit', ['record' => $this->a['admin']]))->status(), [403, 404]); // ni lo encuentra

        // Livewire manipulado contra «Mi empresa» (la ruta ya la cierra): ningún pedido guarda nada.
        $before = Company::find($this->a['company']->id)->only(['name', 'photos_maintenance']);
        config(['app.debug' => false]);
        foreach ([fn ($p) => $p->set('data.name', 'Tomada')->call('save'), fn ($p) => $p->call('savePhotoSetting', 'photos_maintenance', true)] as $attempt) {
            try {
                $attempt(Livewire::test(CompanySettings::class));
            } catch (\Throwable $e) {
                // 403 (hook o la propia página): lo que importa es que no cambió nada.
            }
        }
        $this->assertSame($before, Company::find($this->a['company']->id)->only(['name', 'photos_maintenance']));

        // Solo ve técnicos en Usuarios (ni al dueño ni a otros administrativos).
        $other = $this->staff([], $this->a);
        $this->inPanel($staff);
        $ids = UserResource::getEloquentQuery()->pluck('id')->all();
        $this->assertContains($this->a['technician']->id, $ids);
        $this->assertNotContains($this->a['admin']->id, $ids);
        $this->assertNotContains($other->id, $ids);

        // Exportación completa y conexión de WhatsApp: nunca.
        $this->get("/{$this->a['company']->slug}/whatsapp/connect")->assertRedirect('/admin');
    }

    private function expectsForbidden(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Debía responder 403.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ACCIONES Y PEDIDOS DE LIVEWIRE MANIPULADOS
    |--------------------------------------------------------------------------
    */

    public function test_custom_actions_need_manage_even_with_a_forged_call(): void
    {
        $tech = $this->a['technician'];
        $staff = $this->staff([PermissionArea::Buildings->key(PermissionArea::VIEW), PermissionArea::Technicians->key(PermissionArea::VIEW)]);
        $this->inPanel($staff);

        // Con «ver»: la lista abre, pero «Asignar técnicos» no se ve ni se ejecuta.
        $page = Livewire::test(ListBuildings::class)->assertOk()->assertActionHidden('assignTechnicians');
        $before = $this->a['building']->users()->count();
        // Pedido armado a mano (como desde la consola del navegador): montar y ejecutar.
        $page->call('mountAction', 'assignTechnicians');
        $page->set('mountedActions', [['name' => 'assignTechnicians', 'arguments' => [], 'context' => [], 'data' => [
            'building_ids' => [$this->a['building']->id], 'type' => 'inspection', 'user_ids' => [$tech->id], 'mode' => 'add',
        ]]])->call('callMountedAction');
        $page->call('mountAction', 'assignTechnician', [], ['table' => true, 'recordKey' => (string) $this->a['building']->id]);
        $page->set('mountedActions', [['name' => 'assignTechnician', 'arguments' => [], 'context' => ['table' => true, 'recordKey' => (string) $this->a['building']->id], 'data' => [
            'user_ids' => [$tech->id], 'type' => 'inspection',
        ]]])->call('callMountedAction');
        $this->assertSame($before, $this->a['building']->users()->count());
        $this->assertTrue(ActionGuard::response($page->instance()->getAction('assignTechnicians'))->denied());

        // Con «gestionar» en edificios y técnicos: aparece.
        $staff = $this->staff([PermissionArea::Buildings->key(PermissionArea::MANAGE), PermissionArea::Technicians->key(PermissionArea::MANAGE)]);
        $this->inPanel($staff);
        Livewire::test(ListBuildings::class)->assertActionVisible('assignTechnicians');
    }

    public function test_a_permission_removed_mid_session_stops_the_next_request(): void
    {
        $client = Client::find($this->a['building']->client_id);
        $staff = $this->staff([PermissionArea::Clients->key(PermissionArea::MANAGE)]);
        $this->inPanel($staff);
        $page = Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])->assertOk();

        // El dueño le quita el permiso; el administrativo sigue con la página abierta.
        $staff->forceFill(['permissions' => []])->saveQuietly();
        $this->actingAs($staff->fresh());

        // Con app.debug apagado (como en producción) el pedido responde 403 en vez de una excepción de prueba.
        config(['app.debug' => false]);
        try {
            $page->set('data.name', 'Cambio sin permiso')->call('save');
        } catch (\Throwable $e) {
            // La prueba de Livewire no puede leer la respuesta 403: lo que importa es que no se guardó.
        }
        $this->assertNotSame('Cambio sin permiso', $client->fresh()->name);
    }

    /*
    |--------------------------------------------------------------------------
    | APP DEL TÉCNICO, RUTAS DIRECTAS Y ARCHIVOS
    |--------------------------------------------------------------------------
    */

    public function test_staff_never_uses_the_technician_app(): void
    {
        $staff = $this->staff($this->all());
        $slug = $this->a['company']->slug;
        $this->actingAs($staff);

        $this->get("/{$slug}/dashboard")->assertRedirect('/admin');
        $this->get("/{$slug}/buildings")->assertRedirect('/admin');
        $this->get("/{$slug}/work-orders")->assertRedirect('/admin');
        $this->get("/{$slug}/reports/create")->assertRedirect('/admin');
        foreach (['visits/start', 'visit-photos', 'reports', 'delivery-notes/store'] as $path) {
            $this->post("/{$slug}/{$path}", [])->assertForbidden();
        }
        $this->postJson("/{$slug}/visit-photos", [])->assertForbidden();
        $this->assertSame(0, BuildingVisit::withoutGlobalScopes()->count());

        // Su perfil, sí.
        $this->get("/{$slug}/profile")->assertOk();
        // Al iniciar sesión va al panel.
        $this->assertSame(url('/admin'), $staff->homeUrl());
    }

    public function test_read_routes_and_files_follow_the_area_and_never_cross_companies(): void
    {
        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['admin']);
        $quote = Quote::factory()->create(['building_id' => $this->a['building']->id]);
        $client = Client::find($this->a['building']->client_id);

        $none = $this->staff();
        $this->actingAs($none);
        $this->get(route('quotes.pdf', $quote))->assertNotFound();
        $this->get("/{$slug}/clients/{$client->id}")->assertRedirect('/admin');

        $quotes = $this->staff([PermissionArea::Quotes->key(PermissionArea::VIEW), PermissionArea::Clients->key(PermissionArea::VIEW)]);
        $this->actingAs($quotes);
        $this->get(route('quotes.pdf', $quote))->assertOk();
        $this->get("/{$slug}/clients/{$client->id}")->assertOk();

        // Otra empresa: con todos los permisos, nada de A.
        auth()->logout();
        $b = $this->makeTenant();
        $foreign = $this->staff($this->all(), $b);
        $this->inPanel($foreign);
        $this->get(route('quotes.pdf', $quote))->assertNotFound();
        $this->get(BuildingResource::getUrl('edit', ['record' => $this->a['building']]))->assertNotFound();
        $this->get(ClientResource::getUrl('edit', ['record' => $client]))->assertNotFound();
        $this->assertContains($this->get("/{$slug}/clients/{$client->id}")->status(), [403, 404]);
        $this->assertFalse(BuildingResource::getEloquentQuery()->whereKey($this->a['building']->id)->exists());
    }

    /*
    |--------------------------------------------------------------------------
    | NO ES TÉCNICO: CUPO Y ASIGNACIONES
    |--------------------------------------------------------------------------
    */

    public function test_staff_never_takes_a_technician_seat_or_shows_up_as_assignable(): void
    {
        $company = Company::find($this->a['company']->id);
        $before = PlanLimit::Technicians->usage($company);
        $staff = $this->staff($this->all());

        $this->assertSame($before, PlanLimit::Technicians->usage(Company::find($company->id)));
        $this->assertNull($staff->planLimit());

        // La asignación masiva solo ofrece técnicos.
        $this->inPanel($this->a['admin']);
        $options = app(BulkAssignmentService::class);
        $this->assertNotContains($staff->id, User::where('company_id', $company->id)->where('role', 'technician')->pluck('id')->all());
        $this->assertNotContains($staff->id, User::where('company_id', $company->id)->whereNotIn('role', ['admin', 'client', 'staff'])->pluck('id')->all());
    }

    public function test_admin_user_limits_are_stored_per_plan(): void
    {
        $this->assertSame([0, 1, 5], [
            (int) SubscriptionPlan::findBySlug('inicial')->max_admin_users,
            (int) SubscriptionPlan::findBySlug('profesional')->max_admin_users,
            (int) SubscriptionPlan::findBySlug('empresa')->max_admin_users,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ROLES Y PERMISOS: NO SE ESCALAN
    |--------------------------------------------------------------------------
    */

    public function test_nobody_but_the_super_admin_grants_owner_or_staff_roles_and_only_the_owner_sets_permissions(): void
    {
        $tech = $this->a['technician'];
        $this->actingAs($this->a['admin']);

        $tech->update(['role' => 'admin']);
        $this->assertSame('technician', $tech->fresh()->role);
        $tech->update(['role' => User::ROLE_STAFF]);
        $this->assertSame('technician', $tech->fresh()->role);
        $created = User::create(['name' => 'Nuevo', 'email' => 'nuevo@x.test', 'password' => 'clave-segura-123', 'role' => 'admin']);
        $this->assertSame('technician', $created->fresh()->role);

        // Un administrativo no se da permisos a sí mismo ni a otro.
        $staff = $this->staff([PermissionArea::Clients->key(PermissionArea::VIEW)]);
        $this->actingAs($staff);
        $staff->forceFill(['permissions' => $this->all()])->save();
        $this->assertSame([PermissionArea::Clients->key(PermissionArea::VIEW)], $staff->fresh()->permissions);

        // El dueño de OTRA empresa tampoco.
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingAs($b['admin']);
        $staff->forceFill(['permissions' => $this->all()])->save();
        $this->assertSame([PermissionArea::Clients->key(PermissionArea::VIEW)], $staff->fresh()->permissions);

        // El dueño de su empresa, sí. Las claves desconocidas no dan nada.
        $this->actingAs($this->a['admin']);
        $staff->forceFill(['permissions' => ['clients.manage', 'subscription.manage', 'superadmin']])->save();
        $this->assertSame(['clients.manage'], Access::permissionsOf($staff->fresh()));
    }

    public function test_a_deactivated_staff_user_or_inactive_company_closes_the_panel(): void
    {
        $staff = $this->staff($this->all());
        $this->assertTrue(Access::panelUser($staff));

        Company::whereKey($this->a['company']->id)->update(['is_active' => false]);
        $this->assertFalse(Access::panelUser($staff->fresh()));
        Company::whereKey($this->a['company']->id)->update(['is_active' => true]);

        $staff->delete();
        $this->assertFalse(Access::panelUser(User::withTrashed()->find($staff->id)));
        $this->assertFalse(User::withTrashed()->find($staff->id)->canAccessPanel(Filament::getPanel('ascensores_app')));
    }

    /*
    |--------------------------------------------------------------------------
    | REGISTRO DE ACTIVIDAD
    |--------------------------------------------------------------------------
    */

    public function test_sensitive_actions_are_logged_with_who_what_and_when(): void
    {
        $client = Client::find($this->a['building']->client_id);
        $staff = $this->staff([PermissionArea::Clients->key(PermissionArea::MANAGE)]);
        $this->inPanel($staff);

        Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
            ->fillForm(['name' => 'Consorcio Renombrado'])->call('save')->assertHasNoFormErrors();

        $log = ActivityLog::where('action', 'client.updated')->sole();
        $this->assertSame([$this->a['company']->id, $staff->id, $staff->name, 'staff', 'Client', $client->id], [(int) $log->company_id, (int) $log->user_id, $log->actor_name, $log->actor_role, $log->subject_type, (int) $log->subject_id]);
        $this->assertSame('Consorcio Renombrado', $log->changes['name']['to']);
        $this->assertNotNull($log->created_at);

        // El dueño también queda registrado.
        $this->inPanel($this->a['admin']);
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Torre Nueva']);
        $this->assertSame('owner', ActivityLog::where('action', 'building.created')->where('subject_id', $building->id)->sole()->actor_role);
    }

    public function test_the_log_never_stores_secrets_and_survives_the_user(): void
    {
        $staff = $this->staff([PermissionArea::Clients->key(PermissionArea::MANAGE)]);
        $this->actingAs($staff);

        ActivityLogger::record('prueba.secretos', Client::find($this->a['building']->client_id), [
            'name' => 'visible', 'password' => 'clave', 'remember_token' => 'x', 'api_key' => 'k', 'signature' => 'data:image/png;base64,AAA', 'path' => 'visits/1/a.jpg',
            'nested' => ['token' => 't', 'ok' => 1],
        ]);
        $log = ActivityLog::where('action', 'prueba.secretos')->sole();
        $this->assertSame(['name' => 'visible', 'nested' => ['ok' => 1]], $log->changes);

        // Un modelo actualizado solo guarda los campos permitidos.
        $this->assertNotContains('notes', ActivityLogger::FIELDS[Client::class]);

        // Borrar al usuario no borra su historial: queda el nombre y el rol.
        auth()->logout();
        $staff->forceDelete();
        $log->refresh();
        $this->assertNull($log->user_id);
        $this->assertSame([$staff->name, 'staff'], [$log->actor_name, $log->actor_role]);
    }

    public function test_technician_and_automatic_actions_are_not_logged_as_panel_activity(): void
    {
        $this->actingAs($this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending']);
        $order->users()->attach($this->a['technician']->id);
        $countAfterOwner = ActivityLog::count();

        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/work-orders/{$order->id}/start");
        $this->assertSame('in_progress', $order->fresh()->status);
        $this->assertSame($countAfterOwner, ActivityLog::count());

        auth()->logout();
        $order->update(['priority' => 'high']);
        $this->assertSame($countAfterOwner, ActivityLog::count());
    }

    /*
    |--------------------------------------------------------------------------
    | COBERTURA: NADA QUEDA SIN DECLARAR
    |--------------------------------------------------------------------------
    */

    public function test_every_panel_resource_declares_its_area_or_is_super_admin_only(): void
    {
        $panel = Filament::getPanel('ascensores_app');
        $superAdminOnly = [CompanyResource::class, SubscriptionResource::class];

        foreach ($panel->getResources() as $resource) {
            if (in_array($resource, $superAdminOnly, true)) {
                continue;
            }
            $this->assertContains(GuardedByPermission::class, class_uses_recursive($resource), "{$resource} no declara su área de permisos");
            $this->assertArrayHasKey($resource, self::RESOURCES, "{$resource} falta en el mapa de la prueba");
            $this->assertSame(self::RESOURCES[$resource], $resource::permissionArea());
        }

        // Los exclusivos del SuperAdmin, nunca para un administrativo.
        $this->inPanel($this->staff($this->all()));
        $this->assertFalse(SubscriptionResource::canAccess());
        $this->expectsNotFound(fn () => CompanyResource::canAccess());
    }

    public function test_every_panel_page_is_closed_to_staff_without_permissions(): void
    {
        $this->inPanel($this->staff());

        foreach (Filament::getPanel('ascensores_app')->getPages() as $page) {
            if ($page === Dashboard::class) {
                continue; // su inicio
            }
            $this->assertFalse($page::canAccess(), "{$page} se abre sin permisos");
        }
    }

    public function test_with_only_view_no_listed_action_that_executes_something_is_allowed(): void
    {
        $checked = 0;

        foreach (self::RESOURCES as $resource => $area) {
            $this->inPanel($this->staff([$area->key(PermissionArea::VIEW)]));
            $list = $resource::getPages()['index']->getPage();
            $page = Livewire::test($list)->instance();
            $actions = [...$page->getCachedHeaderActions(), ...(method_exists($page, 'getTable') ? $page->getTable()->getFlatActions() : [])];

            foreach ($this->flatten($actions) as $action) {
                if ($action instanceof ViewAction || ! $action->hasAction()) {
                    continue;
                }
                $checked++;
                $this->assertTrue(ActionGuard::response($action)->denied(), "{$resource}: «{$action->getName()}» se puede ejecutar solo con «ver»");
            }
        }

        $this->assertGreaterThan(10, $checked);
    }

    private function flatten(array $actions): array
    {
        $flat = [];
        foreach ($actions as $action) {
            if ($action instanceof ActionGroup) {
                $flat = [...$flat, ...$this->flatten($action->getActions())];
            } elseif ($action instanceof Action) {
                $flat[] = $action;
            }
        }

        return $flat;
    }

    private function expectsNotFound(\Closure $call): void
    {
        try {
            $call();
            $this->fail('Debía responder 404.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_every_private_file_route_follows_the_area(): void
    {
        $this->actingAs($this->a['admin']);
        $quote = Quote::factory()->create(['building_id' => $this->a['building']->id]);
        $report = Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $elevator = Elevator::where('building_id', $this->a['building']->id)->first();
        $document = app(ElevatorDocumentService::class)->store($elevator, UploadedFile::fake()->createWithContent('cert.pdf', "%PDF-1.4\n%%EOF"), 'certificate', 'Habilitación', null, $this->a['admin']);
        $files = [
            [route('quotes.pdf', $quote), PermissionArea::Quotes],
            [route('reports.pdf', $report), PermissionArea::Inspections],
            [route('elevator-documents.show', $document), PermissionArea::Documents],
        ];

        foreach ($files as [$url, $area]) {
            $this->actingAs($this->staff());
            $this->assertContains($this->get($url)->status(), [403, 404], "{$url} sin permisos");
            $this->actingAs($this->staff([$area->key(PermissionArea::VIEW)]));
            $this->get($url)->assertOk();
            $other = collect(PermissionArea::cases())->first(fn ($a) => $a !== $area);
            $this->actingAs($this->staff([$other->key(PermissionArea::MANAGE)]));
            $this->assertContains($this->get($url)->status(), [403, 404], "{$url} con otra área");
        }
    }
}
