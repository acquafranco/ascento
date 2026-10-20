<?php

namespace Tests\Feature\Clients;

use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Models\Building;
use App\Models\Client;
use App\Models\ClientInteraction;
use App\Models\PortalMembership;
use App\Models\User;
use App\Services\Portal\PortalInvitations;
use App\Services\Staff\StaffManager;
use App\Support\HomeRedirect;
use App\Support\Portal\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Clientes y su portal: el listado muestra quién tiene el portal activo;
 * desactivar un cliente cierra su portal (con un mensaje claro, sin bucles)
 * hasta reactivarlo; eliminarlo (solo sin historial) se lleva sus accesos.
 */
class ClientPortalStatusTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private const PASSWORD = 'una-clave-larga-1';

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');
    }

    private function client(array $tenant, string $name): Client
    {
        return Client::factory()->create(['company_id' => $tenant['company']->id, 'name' => $name]);
    }

    /** Persona del portal ya activada (o solo invitada) con acceso a un cliente. */
    private function portalUser(Client $client, string $email, bool $activated = true): User
    {
        $user = User::withTrashed()->where('email', $email)->first();

        if (! $user) {
            $user = new User(['name' => 'Persona', 'email' => $email, 'password' => bcrypt(self::PASSWORD)]);
            $user->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id, 'portal_activated_at' => $activated ? now() : null])->save();
        }

        $membership = PortalMembership::firstOrNew(['user_id' => $user->id, 'client_id' => $client->id]);
        $membership->forceFill(['company_id' => $client->company_id, 'invited_at' => now(), 'activated_at' => $activated ? now() : null])->save();

        return $user;
    }

    private function login(string $email)
    {
        auth()->logout();
        $this->app['auth']->forgetGuards();

        return $this->post('/login', ['email' => $email, 'password' => self::PASSWORD]);
    }

    private function list(?User $as = null)
    {
        $this->actingInPanel($as ?? $this->a['admin']);

        return Livewire::test(ListClients::class);
    }

    public function test_the_list_shows_which_clients_have_the_portal_active(): void
    {
        $active = $this->client($this->a, 'Consorcio Activo');
        $this->portalUser($active, 'activa@cliente.test');
        $invited = $this->client($this->a, 'Consorcio Invitado');
        $this->portalUser($invited, 'invitada@cliente.test', activated: false);
        $closed = $this->client($this->a, 'Consorcio Cerrado');
        $this->portalUser($closed, 'cerrada@cliente.test');
        PortalMembership::where('client_id', $closed->id)->update(['deactivated_at' => now()]);
        $none = $this->client($this->a, 'Consorcio Sin Portal');

        $this->list()
            ->assertTableColumnStateSet('portal', 'Activo', $active)
            ->assertTableColumnStateSet('portal', 'Invitado', $invited)
            ->assertTableColumnStateSet('portal', 'Desactivado', $closed)
            ->assertTableColumnStateSet('portal', 'Sin portal', $none)
            ->assertTableColumnStateSet('status', 'Activo', $active)
            ->filterTable('portal', true)
            ->assertCanSeeTableRecords([$active])
            ->assertCanNotSeeTableRecords([$invited, $closed, $none]);

        // Desactivado: el listado lo muestra (filtro «Desactivados») con el portal suspendido.
        $active->delete();
        $this->list()->filterTable('trashed', false)
            ->assertTableColumnStateSet('status', 'Desactivado', $active)
            ->assertTableColumnStateSet('portal', 'Suspendido', $active);
    }

    public function test_a_deactivated_client_cannot_enter_and_is_told_why_without_a_redirect_loop(): void
    {
        $client = $this->client($this->a, 'Consorcio Norte');
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $user = $this->portalUser($client, 'norte@cliente.test');
        PortalInvitations::syncBuildings(PortalMembership::where('user_id', $user->id)->sole(), [$building->id]);

        $this->login($user->email)->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk();

        // El admin lo desactiva desde el listado con la sesión del cliente abierta.
        $this->list()->callTableAction('delete', $client);
        $this->assertSoftDeleted($client);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);

        // La sesión abierta deja de servir y se le explica (antes: /portal → /portal en bucle).
        $this->get(route('portal.home'))->assertRedirect(route('portal.login'));
        $this->assertGuest();
        $this->get(route('portal.login'))->assertOk()->assertSee(HomeRedirect::PORTAL_DISABLED_MESSAGE);
        $this->assertSame([], PortalAccess::buildingIds($user)->all());

        // Intenta ingresar de nuevo: el mismo mensaje, y no queda adentro.
        $this->login($user->email)->assertRedirect(route('portal.login'));
        $this->assertGuest();
        $this->followRedirects($this->login($user->email))->assertOk()->assertSee(HomeRedirect::PORTAL_DISABLED_MESSAGE);

        // Con una contraseña incorrecta no se revela nada (error de siempre).
        auth()->logout();
        $this->post('/login', ['email' => $user->email, 'password' => 'otra-cualquiera-9'])->assertSessionHasErrors('email');
        $this->assertNotSame(HomeRedirect::PORTAL_DISABLED_MESSAGE, session('status'));

        // Reactivado: vuelve a entrar y ve su edificio, sin que nadie toque su acceso.
        $this->list()->filterTable('trashed', false)->callTableAction('restore', $client);
        $this->assertNotSoftDeleted($client);
        $this->login($user->email)->assertRedirect(route('portal.home'));
        $this->get(route('portal.building', $building))->assertOk();
    }

    public function test_deactivating_one_client_keeps_the_persons_other_portal_accesses(): void
    {
        $north = $this->client($this->a, 'Consorcio Norte');
        $other = $this->client($this->b, 'Consorcio de otra empresa');
        $user = $this->portalUser($north, 'doble@cliente.test');
        $this->portalUser($other, 'doble@cliente.test');

        $north->delete();

        $this->login($user->email)->assertRedirect(route('portal.home'));
        $this->assertSame([(int) $this->b['company']->id], PortalAccess::memberships($user)->pluck('company_id')->map(fn ($id) => (int) $id)->all());
        $this->get(route('portal.home'))->assertOk();
    }

    public function test_erasing_a_client_without_history_removes_its_portal_access(): void
    {
        $client = $this->client($this->a, 'Consorcio a borrar');
        $only = $this->portalUser($client, 'solo@cliente.test');
        $shared = $this->portalUser($client, 'compartida@cliente.test');
        $kept = $this->client($this->b, 'Cliente de otra empresa');
        $this->portalUser($kept, 'compartida@cliente.test');
        ClientInteraction::withoutGlobalScopes()->forceCreate(['company_id' => $client->company_id, 'client_id' => $client->id, 'user_id' => $this->a['admin']->id, 'type' => 'call', 'result' => 'done', 'notes' => 'Llamó', 'occurred_at' => now()]);

        $this->list()->callTableAction('erase', $client)->assertNotified('Cliente eliminado');

        $this->assertDatabaseMissing('clients', ['id' => $client->id]);
        $this->assertSame(0, PortalMembership::where('client_id', $client->id)->count());
        $this->assertDatabaseMissing('client_interactions', ['client_id' => $client->id]);

        // Solo entraba por este cliente: su cuenta del portal queda desactivada (no borrada).
        $this->assertSoftDeleted($only);
        $this->login($only->email)->assertSessionHasErrors('email');
        $this->assertGuest();

        // También entraba por otro cliente (de otra empresa): conserva la cuenta y ese
        // acceso. La cuenta no se muda de empresa (eso solo lo hace el SuperAdmin).
        $shared->refresh();
        $this->assertFalse($shared->trashed());
        $this->assertNull($shared->client_id);
        $this->assertSame((int) $this->a['company']->id, (int) $shared->company_id);
        $this->assertSame(1, PortalMembership::where('user_id', $shared->id)->where('client_id', $kept->id)->whereNull('deactivated_at')->count());
        $this->login($shared->email)->assertRedirect(route('portal.home'));

        // Si lo vuelven a invitar más adelante, se reactiva la misma cuenta.
        $again = $this->client($this->a, 'Consorcio nuevo');
        auth()->logout();
        $this->a['admin']->markEmailAsVerified();
        app(PortalInvitations::class)->invite($again, 'Persona', 'solo@cliente.test', [], $this->a['admin']);
        $this->assertFalse($only->fresh()->trashed());
        $this->assertSame(1, User::withTrashed()->where('email', 'solo@cliente.test')->count());
    }

    public function test_a_client_with_history_cannot_be_erased_only_deactivated(): void
    {
        $client = $this->client($this->a, 'Consorcio con edificios');
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $building->delete(); // aun desactivado, es historial
        $user = $this->portalUser($client, 'historial@cliente.test');

        $this->list()->callTableAction('erase', $client)->assertNotified('No se puede eliminar');

        $this->assertNotSoftDeleted($client);
        $this->assertSame(1, Building::withTrashed()->where('client_id', $client->id)->count());
        $this->assertSame(1, PortalMembership::where('client_id', $client->id)->count());
        $this->assertFalse($user->fresh()->trashed());
    }

    public function test_erasing_works_from_the_deactivated_list_and_the_edit_page(): void
    {
        $fromList = $this->client($this->a, 'Desactivado sin historial');
        $fromList->delete();
        $this->list()->filterTable('trashed', false)->callTableAction('erase', $fromList);
        $this->assertDatabaseMissing('clients', ['id' => $fromList->id]);

        $fromEdit = $this->client($this->a, 'Desde la edición');
        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditClient::class, ['record' => $fromEdit->getRouteKey()])->callAction('erase')->assertRedirect();
        $this->assertDatabaseMissing('clients', ['id' => $fromEdit->id]);
    }

    public function test_only_the_owner_erases_and_never_a_client_of_another_company(): void
    {
        $client = $this->client($this->a, 'Consorcio protegido');
        $this->portalUser($client, 'protegido@cliente.test');

        // Un administrativo que gestiona clientes desactiva y reactiva, pero no elimina.
        $this->actingAs($this->a['admin']);
        $staff = app(StaffManager::class)->create($this->a['admin'], ['name' => 'Oficina', 'email' => 'oficina@a.test', 'password' => 'clave-segura-123'], ['clients' => 'manage']);
        $this->list($staff)->assertTableActionHidden('erase', $client)->assertTableActionVisible('delete', $client);

        // Otra empresa no lo ve ni lo puede tocar.
        $this->list($this->b['admin'])->assertCanNotSeeTableRecords([$client]);

        $this->assertNotSoftDeleted($client);
        $this->assertSame(1, PortalMembership::where('client_id', $client->id)->count());
    }
}
