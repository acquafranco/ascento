<?php

namespace Tests\Feature\Portal;

use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Models\Building;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\PortalMembership;
use App\Models\User;
use App\Notifications\PortalAccessGrantedNotification;
use App\Notifications\PortalInvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\Notifications\ClientShareNotifier;
use App\Services\Portal\PortalInvitations;
use App\Support\Portal\PortalAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Una persona, una cuenta; acceso explícito por empresa y por cliente.
 * Elegir la empresa en el portal nunca da acceso a lo que no se autorizó.
 */
class PortalIdentityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $clientA;

    private Client $clientB;

    private Building $buildingA;

    private Building $buildingB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->clientA = Client::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Consorcio Arenales']);
        $this->clientB = Client::factory()->create(['company_id' => $this->b['company']->id, 'name' => 'Consorcio Bulnes']);
        $this->buildingA = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->clientA->id, 'name' => 'Arenales 1500']);
        $this->buildingB = Building::factory()->create(['company_id' => $this->b['company']->id, 'client_id' => $this->clientB->id, 'name' => 'Bulnes 900']);
    }

    private function inviteAs(array $tenant, Client $client, string $email, array $buildings)
    {
        $this->actingInPanel($tenant['admin']);
        $component = Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $client, 'pageClass' => EditClient::class])
            ->callTableAction('createPortalUser', data: ['name' => 'Laura Pérez', 'email' => $email, 'building_ids' => array_map(fn ($b) => $b->id, $buildings)]);
        auth()->logout();

        return $component;
    }

    /** Activa la cuenta con el enlace de la invitación (como lo haría la persona). */
    private function activate(User $user): void
    {
        Notification::assertSentTo($user, PortalInvitationNotification::class, function ($n) use ($user) {
            $token = basename(parse_url($n->toMail($user)->actionUrl, PHP_URL_PATH));
            $this->post(route('portal.invitation.store'), ['token' => $token, 'email' => $user->email, 'password' => 'clave-portal-123', 'password_confirmation' => 'clave-portal-123']);

            return true;
        });
    }

    private function shared(Building $building, string $description): DeliveryNote
    {
        auth()->logout();
        $note = DeliveryNote::factory()->create(['building_id' => $building->id, 'company_id' => $building->company_id, 'description' => $description]);
        $note->shareWithClient(true);

        return $note;
    }

    public function test_one_person_with_access_in_two_companies_switches_context_explicitly(): void
    {
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA])->assertHasNoTableActionErrors();
        $laura = User::where('email', 'laura@consorcios.test')->sole();
        $this->activate($laura);

        // La segunda empresa invita el MISMO email: no se crea otra cuenta ni otra invitación con enlace.
        Notification::fake();
        $this->inviteAs($this->b, $this->clientB, 'Laura@Consorcios.test', [$this->buildingB])->assertHasNoTableActionErrors();
        $this->assertSame(1, User::where('email', 'laura@consorcios.test')->count());
        Notification::assertSentTo($laura, PortalAccessGrantedNotification::class);
        Notification::assertNotSentTo($laura, PortalInvitationNotification::class);
        $this->assertSame(2, PortalMembership::where('user_id', $laura->id)->count());
        $this->assertSame('Activo', PortalInvitations::status(PortalMembership::where('user_id', $laura->id)->where('client_id', $this->clientB->id)->sole()));

        $noteA = $this->shared($this->buildingA, 'Trabajo de empresa A');
        $noteB = $this->shared($this->buildingB, 'Trabajo de empresa B');

        // Ingresa con su única contraseña; ve la primera empresa y nada de la otra.
        $this->post('/login', ['email' => $laura->email, 'password' => 'clave-portal-123'])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee('Arenales 1500')->assertDontSee('Bulnes 900')->assertSee($this->b['company']->name); // en el selector
        $this->get(route('portal.delivery-note', $noteA->number))->assertOk();
        $this->get(route('portal.building', $this->buildingB))->assertNotFound();

        // Cambia de empresa: ahora ve B y no A (aunque conozca la URL).
        $this->post(route('portal.switch-company'), ['company' => $this->b['company']->id])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee('Bulnes 900')->assertDontSee('Arenales 1500');
        $this->get(route('portal.building', $this->buildingB))->assertOk();
        $this->get(route('portal.building', $this->buildingA))->assertNotFound();
        $this->get(route('portal.documents'))->assertOk()->assertSee('Remito '.$noteB->number)->assertDontSee('Trabajo de empresa A');

        // No puede "elegir" una empresa en la que no tiene acceso.
        $c = $this->makeTenant()['company'];
        $this->post(route('portal.switch-company'), ['company' => $c->id])->assertNotFound();
    }

    public function test_notifications_from_each_company_reach_the_same_person_only_for_authorized_buildings(): void
    {
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA]);
        $this->inviteAs($this->b, $this->clientB, 'laura@consorcios.test', [$this->buildingB]);
        $laura = User::where('email', 'laura@consorcios.test')->sole();
        $otherB = Building::factory()->create(['company_id' => $this->b['company']->id, 'client_id' => $this->clientB->id, 'name' => 'Bulnes 1200']);

        app(ClientShareNotifier::class)->shared([$this->shared($this->buildingA, 'A'), $this->shared($this->buildingB, 'B'), $this->shared($otherB, 'no autorizado')]);

        $this->assertSame(2, $laura->notifications()->count());
        $this->assertEqualsCanonicalizing([$this->a['company']->id, $this->b['company']->id], $laura->notifications()->pluck('company_id')->all());
    }

    public function test_deactivating_in_one_company_does_not_touch_the_other(): void
    {
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA]);
        $laura = User::where('email', 'laura@consorcios.test')->sole();
        $this->activate($laura);
        $this->inviteAs($this->b, $this->clientB, 'laura@consorcios.test', [$this->buildingB]);
        $membershipB = PortalMembership::where('user_id', $laura->id)->where('client_id', $this->clientB->id)->sole();

        // B elige B en el portal y después B le quita el acceso con la sesión abierta.
        $this->post('/login', ['email' => $laura->email, 'password' => 'clave-portal-123']);
        $this->post(route('portal.switch-company'), ['company' => $this->b['company']->id]);
        $this->get(route('portal.building', $this->buildingB))->assertOk();

        $this->actingInPanel($this->b['admin']);
        Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->clientB, 'pageClass' => EditClient::class])->callTableAction('deactivate', $membershipB);
        $this->actingAs($laura->fresh());

        $this->get(route('portal.building', $this->buildingB))->assertNotFound(); // cae a la empresa A
        $this->get(route('portal.home'))->assertOk()->assertSee('Arenales 1500')->assertDontSee($this->b['company']->name);
        $this->assertFalse($laura->fresh()->trashed()); // la cuenta global sigue
        $this->assertSame([$this->buildingA->id], PortalAccess::buildingIds($laura->fresh())->all());

        // Si ninguna empresa le deja acceso: al ingresar, se cierra la sesión con un aviso.
        PortalMembership::where('user_id', $laura->id)->update(['deactivated_at' => now()]);
        auth()->logout();
        $this->post('/login', ['email' => $laura->email, 'password' => 'clave-portal-123']);
        $this->assertGuest();
    }

    public function test_the_admin_only_sees_and_manages_accesses_of_their_own_client(): void
    {
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA]);
        $this->inviteAs($this->b, $this->clientB, 'laura@consorcios.test', [$this->buildingB]);
        $laura = User::where('email', 'laura@consorcios.test')->sole();
        $membershipA = PortalMembership::where('user_id', $laura->id)->where('client_id', $this->clientA->id)->sole();

        $this->actingInPanel($this->b['admin']);
        $b = Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->clientB, 'pageClass' => EditClient::class]);
        $b->assertCanSeeTableRecords([PortalMembership::where('client_id', $this->clientB->id)->sole()])->assertCanNotSeeTableRecords([$membershipA]);

        // Cambiar edificios de B no borra los de A (y no se pueden agregar edificios ajenos).
        $b->callTableAction('editBuildings', PortalMembership::where('client_id', $this->clientB->id)->sole(), data: ['building_ids' => [$this->buildingB->id, $this->buildingA->id]]);
        $this->assertEqualsCanonicalizing([$this->buildingA->id, $this->buildingB->id], PortalAccess::buildingIds($laura)->all());
        $this->assertSame([$this->buildingA->id], $membershipA->buildingIds());

        // Ni actuar sobre el acceso de otra empresa.
        try {
            $b->callTableAction('deactivate', $membershipA);
        } catch (\Throwable) {
        }
        $this->assertNull($membershipA->fresh()->deactivated_at);
    }

    public function test_staff_emails_and_repeated_invitations_are_rejected_without_changes(): void
    {
        $this->inviteAs($this->b, $this->clientB, $this->a['technician']->email, [$this->buildingB])
            ->assertHasTableActionErrors(['email']);
        $this->assertSame('technician', $this->a['technician']->fresh()->role);
        $this->assertSame(0, PortalMembership::count());

        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA])->assertHasNoTableActionErrors();
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA])->assertHasTableActionErrors(['email']);
        $this->assertSame(1, PortalMembership::count());
    }

    public function test_password_recovery_is_one_per_person_and_knowing_the_email_gives_no_access(): void
    {
        $this->inviteAs($this->a, $this->clientA, 'laura@consorcios.test', [$this->buildingA]);
        $this->inviteAs($this->b, $this->clientB, 'laura@consorcios.test', [$this->buildingB]);
        $laura = User::where('email', 'laura@consorcios.test')->sole();

        // Sin activar, la cuenta no tiene una contraseña conocida.
        $this->post('/login', ['email' => $laura->email, 'password' => 'cualquier-cosa']);
        $this->assertGuest();

        Notification::fake();
        $this->travel(2)->minutes(); // (recuperar y la invitación comparten el límite de 1 pedido por minuto)
        $this->post('/forgot-password', ['email' => $laura->email]);
        Notification::assertSentToTimes($laura, ResetPasswordNotification::class, 1);
    }
}
