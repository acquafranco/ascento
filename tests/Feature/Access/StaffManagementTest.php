<?php

namespace Tests\Feature\Access;

use App\Enums\PermissionArea;
use App\Exceptions\PlanLimitReachedException;
use App\Filament\Resources\Buildings\BuildingResource;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Quotes\Pages\ViewQuote;
use App\Filament\Resources\StaffUsers\Pages\CreateStaffUser;
use App\Filament\Resources\StaffUsers\Pages\EditStaffUser;
use App\Filament\Resources\StaffUsers\Pages\ListStaffUsers;
use App\Filament\Resources\StaffUsers\StaffUserResource;
use App\Filament\Resources\Users\UserResource;
use App\Jobs\NotifyCompanyAdmins;
use App\Models\ActivityLog;
use App\Models\Quote;
use App\Models\Report;
use App\Models\User;
use App\Notifications\NewReportNotification;
use App\Services\Staff\StaffManager;
use App\Support\Access\Access;
use App\Support\Access\ActionGuard;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 6, PR 2: el dueño crea, edita, da permisos, desactiva y reactiva a
 * sus administrativos, con el límite del plan (Inicial 0, Profesional 1,
 * Empresa y prueba gratis 5) y la suspensión por exceso al bajar de plan
 * (sin borrar nada). Estado y motivo visibles; nada de esto es para un
 * administrativo ni para otra empresa.
 */
class StaffManagementTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->a = $this->makeTenant(); // prueba gratis: plan Empresa
    }

    private function inPanel(User $user): void
    {
        // Sesión nueva por usuario (AuthenticateSession guarda el hash de la contraseña del anterior).
        $this->flushSession();
        $this->actingAs($user);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
    }

    private function create(string $name, array $levels = [], ?User $owner = null): User
    {
        return app(StaffManager::class)->create($owner ?? $this->a['admin'], [
            'name' => $name, 'email' => str($name)->slug().'@demo.test', 'password' => 'clave-segura-123',
        ], $levels);
    }

    public function test_the_owner_creates_edits_and_grants_permissions_from_the_panel(): void
    {
        $this->inPanel($this->a['admin']);
        $this->get(StaffUserResource::getUrl('index'))->assertOk()->assertSee('0 de 5 administrativos activos');

        Livewire::test(CreateStaffUser::class)
            ->fillForm([
                'name' => 'Laura Oficina', 'email' => 'laura@demo.test', 'phone' => '11 5555-1234', 'password' => 'clave-segura-123',
                'levels.clients' => 'manage', 'levels.quotes' => 'view', 'levels.indicators' => 'view',
            ])->call('create')->assertHasNoFormErrors();

        $staff = User::where('email', 'laura@demo.test')->sole();
        $this->assertSame([User::ROLE_STAFF, $this->a['company']->id], [$staff->role, (int) $staff->company_id]);
        $this->assertEqualsCanonicalizing(['clients.manage', 'quotes.view', 'indicators.view'], Access::permissionsOf($staff));
        $this->assertSame('owner', ActivityLog::where('action', 'staff.created')->sole()->actor_role);

        // Estado y permisos visibles para el dueño.
        Livewire::test(ListStaffUsers::class)->assertCanSeeTableRecords([$staff])->assertSee('Activo')->assertSee('Clientes (gestionar)');

        // Edita datos y permisos: queda registrado quién, a quién y cuándo.
        Livewire::test(EditStaffUser::class, ['record' => $staff->getRouteKey()])
            ->assertFormSet(['levels.clients' => 'manage', 'levels.quotes' => 'view', 'levels.stock' => 'none'])
            ->fillForm(['name' => 'Laura Pérez', 'levels.clients' => 'view', 'levels.stock' => 'manage'])
            ->call('save')->assertHasNoFormErrors();
        $staff->refresh();
        $this->assertSame('Laura Pérez', $staff->name);
        $this->assertEqualsCanonicalizing(['clients.view', 'quotes.view', 'indicators.view', 'stock.manage'], Access::permissionsOf($staff));
        $log = ActivityLog::where('action', 'user.permissions_changed')->sole();
        $this->assertSame([$this->a['admin']->id, $staff->id], [(int) $log->user_id, (int) $log->subject_id]);
        $this->assertEqualsCanonicalizing(['clients.view', 'stock.manage'], $log->changes['added']);
        $this->assertSame(1, ActivityLog::where('action', 'staff.updated')->count());

        // «Gestionar» no existe para Indicadores (solo ver): un valor manipulado no da nada.
        $this->assertSame([], StaffManager::keysFromLevels(['indicators' => 'manage']));
        $this->assertSame(['indicators.view'], StaffManager::keysFromLevels(['indicators' => 'view', 'inventado' => 'manage']));

        // Entra y ve solo lo suyo.
        $this->inPanel($staff);
        $this->assertTrue(ClientResource::canAccess());
        $this->assertFalse(ClientResource::canCreate());
        $this->assertFalse(BuildingResource::canAccess());
        $this->get(ClientResource::getUrl('index'))->assertOk();
        $this->get(BuildingResource::getUrl('index'))->assertForbidden();
    }

    public function test_deactivating_keeps_history_and_permissions_and_reactivating_restores_access(): void
    {
        $staff = $this->create('Pablo Oficina', ['clients' => 'view']);
        $this->inPanel($this->a['admin']);

        Livewire::test(ListStaffUsers::class)->callTableAction('deactivate', $staff);
        $staff = User::withTrashed()->find($staff->id);
        $this->assertTrue($staff->trashed());
        $this->assertSame(['clients.view'], Access::permissionsOf($staff));
        $this->assertFalse(Access::panelUser($staff));
        $this->assertSame(Access::BLOCK_DEACTIVATED, Access::staffBlockReason($staff));
        Livewire::test(ListStaffUsers::class)->assertSee('Desactivado');
        $this->assertSame(1, ActivityLog::where('action', 'staff.deactivated')->where('subject_id', $staff->id)->count());

        Livewire::test(ListStaffUsers::class)->callTableAction('reactivate', $staff);
        $staff->refresh();
        $this->assertFalse($staff->trashed());
        $this->assertTrue(Access::panelUser($staff));
        $this->assertSame(['clients.view'], Access::permissionsOf($staff));
        $this->assertSame(1, ActivityLog::where('action', 'staff.reactivated')->count());
    }

    public function test_plan_limits_inicial_0_profesional_1_empresa_and_trial_5(): void
    {
        // Prueba gratis = Empresa: 5.
        foreach (range(1, 5) as $i) {
            $this->actingAs($this->a['admin']);
            $this->create("Admin {$i}");
        }
        try {
            $this->create('Admin 6');
            $this->fail('El sexto superaba el límite de Empresa.');
        } catch (PlanLimitReachedException) {
        }
        $this->assertSame(5, User::where('role', User::ROLE_STAFF)->count());

        // Inicial: ninguno (ni el formulario se abre).
        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'inicial');
        $this->inPanel($b['admin']);
        $this->get(StaffUserResource::getUrl('create'))->assertRedirect();
        try {
            $this->create('Nadie', [], $b['admin']);
            $this->fail('Inicial no incluye administrativos.');
        } catch (PlanLimitReachedException) {
        }

        // Profesional: uno.
        $this->giveCompanyPlan($b['company'], 'profesional');
        $this->actingAs($b['admin']);
        $first = $this->create('Uno', [], $b['admin']);
        try {
            $this->create('Dos', [], $b['admin']);
            $this->fail('Profesional permite uno.');
        } catch (PlanLimitReachedException) {
        }

        // Reactivar también respeta el cupo: desactivo el primero, creo otro, no puedo reactivar.
        app(StaffManager::class)->deactivate($b['admin'], $first);
        $this->create('Reemplazo', [], $b['admin']);
        try {
            app(StaffManager::class)->reactivate($b['admin'], User::withTrashed()->find($first->id));
            $this->fail('Reactivar superaba el cupo.');
        } catch (PlanLimitReachedException) {
        }
        $this->assertTrue(User::withTrashed()->find($first->id)->trashed());
    }

    public function test_downgrading_suspends_the_excess_without_deleting_and_upgrading_restores(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->actingAs($this->a['admin']);
        $oldest = $this->create('Primera', ['clients' => 'manage']);
        $second = $this->create('Segunda', ['quotes' => 'view']);
        $third = $this->create('Tercera', ['stock' => 'view']);

        // Pasa a Profesional (1): la más antigua sigue; las otras quedan suspendidas, intactas.
        $this->giveCompanyPlan($this->a['company'], 'profesional');
        $this->assertTrue(Access::isActiveStaff($oldest->fresh()));
        foreach ([$second, $third] as $staff) {
            $staff = $staff->fresh();
            $this->assertFalse($staff->trashed());
            $this->assertSame(Access::BLOCK_PLAN, Access::staffBlockReason($staff));
            $this->assertNotSame([], Access::permissionsOf($staff));
        }

        // El dueño ve el estado y el motivo.
        $this->inPanel($this->a['admin']);
        Livewire::test(ListStaffUsers::class)->assertSee('Suspendido por el plan')->assertSee('El plan actual permite 1')->assertSee('3 de 1 administrativos activos');

        // La suspendida no entra: ve el motivo (no un error), ni en el panel ni en la app.
        auth()->logout();
        $this->actingAs($second->fresh());
        $this->get('/admin')->assertRedirect(route('staff.blocked'));
        $this->get(route('staff.blocked'))->assertForbidden()->assertSee('suspendido')->assertSee('permite 1 usuario administrativo');
        $this->get("/{$this->a['company']->slug}/dashboard")->assertRedirect('/admin');
        $this->assertSame(route('staff.blocked'), $second->fresh()->homeUrl());

        // El dueño desactiva a la más antigua: la siguiente recupera el acceso sola.
        app(StaffManager::class)->deactivate($this->a['admin'], $oldest->fresh());
        $this->assertTrue(Access::isActiveStaff($second->fresh()));
        $this->assertFalse(Access::isActiveStaff($third->fresh()));

        // Pasa a Inicial: todas suspendidas. Vuelve a Empresa: todas activas.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $this->assertSame(Access::BLOCK_PLAN, Access::staffBlockReason($second->fresh()));
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->assertTrue(Access::isActiveStaff($second->fresh()));
        $this->assertTrue(Access::isActiveStaff($third->fresh()));
        $this->assertSame(3, User::withTrashed()->where('role', User::ROLE_STAFF)->count());
    }

    public function test_only_the_owner_manages_staff_never_staff_or_another_company(): void
    {
        $this->actingAs($this->a['admin']);
        $staff = $this->create('Con todo', array_fill_keys(array_map(fn ($a) => $a->value, PermissionArea::cases()), 'manage'));
        $peer = $this->create('Otra');

        // Un administrativo con todos los permisos: ni la pantalla ni el servicio.
        $this->inPanel($staff);
        $this->assertFalse(StaffUserResource::canAccess());
        $this->get(StaffUserResource::getUrl('index'))->assertForbidden();
        $this->get(StaffUserResource::getUrl('edit', ['record' => $peer]))->assertForbidden();
        foreach ([
            fn () => app(StaffManager::class)->create($staff, ['name' => 'X', 'email' => 'x@x.test', 'password' => 'clave-segura-123']),
            fn () => app(StaffManager::class)->setPermissions($staff, $peer, ['clients' => 'manage']),
            fn () => app(StaffManager::class)->setPermissions($staff, $staff, ['stock' => 'manage']),
            fn () => app(StaffManager::class)->deactivate($staff, $peer),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Solo el dueño gestiona administrativos.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame([], Access::permissionsOf($peer->fresh()));
        // Tampoco aparece ni se edita desde «Técnicos».
        $this->assertFalse(UserResource::getEloquentQuery()->whereKey($peer->id)->exists());

        // El dueño de otra empresa: no lo encuentra.
        auth()->logout();
        $b = $this->makeTenant();
        $this->inPanel($b['admin']);
        $this->get(StaffUserResource::getUrl('edit', ['record' => $peer]))->assertNotFound();
        try {
            app(StaffManager::class)->deactivate($b['admin'], $peer);
            $this->fail('Otra empresa.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        $this->assertFalse($peer->fresh()->trashed());
        // El dueño de A no ve administrativos en la lista de técnicos (no cambia su pantalla).
        $this->inPanel($this->a['admin']);
        $this->assertFalse(UserResource::getEloquentQuery()->where('role', User::ROLE_STAFF)->exists());
    }

    public function test_notices_reach_staff_only_with_the_area_and_while_active(): void
    {
        $this->actingAs($this->a['admin']);
        $withArea = $this->create('Con inspecciones', ['inspections' => 'view']);
        $without = $this->create('Sin inspecciones', ['clients' => 'manage']);
        auth()->logout();
        $report = Report::factory()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);

        (new NotifyCompanyAdmins(NotifyCompanyAdmins::REPORT, $report->id))->handle();

        Notification::assertSentTo($this->a['admin'], NewReportNotification::class);
        Notification::assertSentTo($withArea, NewReportNotification::class);
        Notification::assertNotSentTo($without, NewReportNotification::class);
        $this->assertTrue($withArea->fresh()->canReceiveAdminPush());

        // Suspendido por el plan: no recibe.
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        Notification::fake();
        (new NotifyCompanyAdmins(NotifyCompanyAdmins::REPORT, $report->id))->handle();
        Notification::assertNotSentTo($withArea, NewReportNotification::class);
    }

    public function test_read_only_actions_need_view_and_sending_to_the_client_needs_manage(): void
    {
        $this->actingAs($this->a['admin']);
        $quote = Quote::factory()->create(['building_id' => $this->a['building']->id, 'status' => Quote::SENT]);
        $viewer = $this->create('Solo ve', ['quotes' => 'view']);

        $this->inPanel($viewer);
        $page = Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])->instance();
        $this->assertTrue(ActionGuard::response($page->getAction('pdf'))->allowed());
        $this->get(route('quotes.pdf', $quote))->assertOk();
        foreach (['link', 'whatsapp', 'send'] as $name) {
            $action = $page->getAction($name);
            if ($action) {
                $this->assertTrue(ActionGuard::response($action)->denied(), "{$name} con solo «ver»");
            }
        }

        $this->actingAs($this->a['admin']);
        $manager = $this->create('Gestiona', ['quotes' => 'manage']);
        $this->inPanel($manager);
        $page = Livewire::test(ViewQuote::class, ['record' => $quote->getRouteKey()])->instance();
        $this->assertTrue(ActionGuard::response($page->getAction('link'))->allowed());
    }
}
