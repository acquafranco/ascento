<?php

namespace Tests\Feature\Portal;

use App\Enums\PlanLimit;
use App\Exceptions\PlanFeatureUnavailableException;
use App\Filament\RelationManagers\PortalUsersRelationManager;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\DeliveryNotes\Pages\ListDeliveryNotes;
use App\Models\Building;
use App\Models\Client;
use App\Models\DeliveryNote;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Notifications\App\PortalAccountActivatedNotification;
use App\Notifications\PortalInvitationNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\Portal\PortalInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Cuentas del portal de punta a punta: invitación → activación → ingreso →
 * recuperación de contraseña, y el corte de acceso (edificio retirado,
 * cuenta desactivada, plan sin portal).
 */
class PortalAccountsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Client $client;

    private Building $authorized;

    private Building $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->client = Client::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Consorcio Pueyrredón']);
        $this->authorized = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Pueyrredón 1200']);
        $this->other = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $this->client->id, 'name' => 'Pueyrredón 1400']);
    }

    private function onPlan(string $slug, ?array $tenant = null): void
    {
        $tenant ??= $this->a;
        $plan = SubscriptionPlan::findBySlug($slug);

        Subscription::updateOrCreate(['company_id' => $tenant['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-'.$tenant['company']->id, 'plan' => $slug,
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $tenant['company']->forgetPlan();
    }

    private function manager()
    {
        $this->actingInPanel($this->a['admin']);

        return Livewire::test(PortalUsersRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => EditClient::class]);
    }

    /** El admin invita; devuelve el usuario y el token que llegó por correo. */
    private function invite(string $email = 'sofia@consorcio.test'): array
    {
        $this->manager()->callTableAction('createPortalUser', data: [
            'name' => 'Sofía Pueyrredón', 'email' => $email, 'building_ids' => [$this->authorized->id],
        ])->assertHasNoTableActionErrors();
        auth()->logout();

        $user = User::where('email', $email)->sole();
        $token = null;
        Notification::assertSentTo($user, PortalInvitationNotification::class, function ($n) use ($user, &$token) {
            $url = $n->toMail($user)->actionUrl;
            $token = basename(parse_url($url, PHP_URL_PATH));

            return str_contains($url, '/portal/activar/') && str_contains($url, urlencode($user->email));
        });

        return [$user, $token];
    }

    /** El token tal como llega en el enlace del correo. */
    private function tokenFrom(ResetPasswordNotification $notification, User $user): string
    {
        return basename(parse_url($notification->toMail($user)->viewData['url'], PHP_URL_PATH));
    }

    private function activate(User $user, string $token, string $password = 'una-clave-larga-1')
    {
        return $this->post(route('portal.invitation.store'), ['token' => $token, 'email' => $user->email, 'password' => $password, 'password_confirmation' => $password]);
    }

    public function test_admin_invites_and_the_client_activates_with_a_single_use_expiring_link(): void
    {
        [$user, $token] = $this->invite();

        // La cuenta existe pero no tiene una contraseña conocida por nadie.
        $this->assertSame([User::ROLE_CLIENT, $this->client->id, $this->a['company']->id], [$user->role, $user->client_id, $user->company_id]);
        $this->assertNotNull($user->portal_invited_at);
        $this->assertNull($user->portal_activated_at);
        $this->assertSame('Invitación enviada', PortalUsersRelationManager::status($user));
        $mail = (new PortalInvitationNotification($token, 'X'))->toMail($user);
        $this->assertStringNotContainsString('contraseña:', mb_strtolower(implode(' ', $mail->introLines))); // nunca una contraseña

        // Enlace inválido / de otra cuenta: mismo mensaje, sin pistas.
        $this->get(route('portal.invitation', ['token' => 'falso', 'email' => $user->email]))->assertStatus(410);
        $this->get(route('portal.invitation', ['token' => $token, 'email' => $this->a['admin']->email]))->assertStatus(410);

        $this->get(route('portal.invitation', ['token' => $token, 'email' => $user->email]))->assertOk()->assertSee('Activá tu cuenta');
        $this->activate($user, $token)->assertRedirect(route('portal.login'));

        $user->refresh();
        $this->assertNotNull($user->portal_activated_at);
        $this->assertSame('Activo', PortalUsersRelationManager::status($user));

        // De un solo uso.
        $this->activate($user, $token, 'otra-clave-larga-2')->assertStatus(410);

        // Ingresa por el login del portal y ve solo su edificio autorizado.
        $this->get(route('portal.login'))->assertOk()->assertSee('Ingresá al portal')->assertDontSee('Crear cuenta');
        $this->post('/login', ['email' => $user->email, 'password' => 'una-clave-larga-1'])->assertRedirect(route('portal.home'));
        $this->get(route('portal.home'))->assertOk()->assertSee('Pueyrredón 1200')->assertDontSee('Pueyrredón 1400');

        // Los admins se enteran (una sola vez).
        $this->assertSame(1, $this->a['admin']->notifications()->where('type', PortalAccountActivatedNotification::class)->count());
    }

    public function test_the_invitation_expires_after_72_hours_and_can_be_resent(): void
    {
        [$user, $token] = $this->invite();

        $this->travel(73)->hours();
        $this->get(route('portal.invitation', ['token' => $token, 'email' => $user->email]))->assertStatus(410);
        $this->activate($user, $token)->assertStatus(410);
        $this->assertSame('Invitación vencida', PortalUsersRelationManager::status($user->fresh()));

        Notification::fake();
        $this->manager()->callTableAction('resendInvitation', $user);
        Notification::assertSentTo($user, PortalInvitationNotification::class);
        $this->assertSame('Invitación enviada', PortalUsersRelationManager::status($user->fresh()));
    }

    public function test_a_technician_or_admin_reset_token_cannot_be_used_as_an_invitation(): void
    {
        $token = PortalInvitations::broker()->createToken($this->a['admin']);

        $this->get(route('portal.invitation', ['token' => $token, 'email' => $this->a['admin']->email]))->assertStatus(410);
        $this->post(route('portal.invitation.store'), ['token' => $token, 'email' => $this->a['admin']->email, 'password' => 'x-clave-larga-9', 'password_confirmation' => 'x-clave-larga-9'])
            ->assertStatus(410);
    }

    public function test_the_client_recovers_the_password_without_the_admin(): void
    {
        [$user, $token] = $this->invite();
        $this->activate($user, $token);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('status');
        $resetToken = null;
        Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use ($user, &$resetToken) {
            $resetToken = $this->tokenFrom($n, $user);

            return true;
        });

        $this->post('/reset-password', ['token' => $resetToken, 'email' => $user->email, 'password' => 'clave-nueva-larga-3', 'password_confirmation' => 'clave-nueva-larga-3'])
            ->assertRedirect(route('portal.login'));
        $this->post('/login', ['email' => $user->email, 'password' => 'clave-nueva-larga-3'])->assertRedirect(route('portal.home'));

        // Mismo mensaje para un email inexistente.
        auth()->logout();
        $this->post('/forgot-password', ['email' => 'nadie@nada.test'])->assertSessionHas('status');
    }

    public function test_staff_password_recovery_still_works(): void
    {
        foreach ([$this->a['admin'], $this->a['technician']] as $user) {
            $this->post('/forgot-password', ['email' => $user->email]);
            Notification::assertSentTo($user, ResetPasswordNotification::class, function ($n) use ($user) {
                $this->post('/reset-password', ['token' => $this->tokenFrom($n, $user), 'email' => $user->email, 'password' => 'clave-staff-larga-1', 'password_confirmation' => 'clave-staff-larga-1'])
                    ->assertRedirect(route('login'));

                return true;
            });
            $this->post('/login', ['email' => $user->email, 'password' => 'clave-staff-larga-1']);
            $this->assertAuthenticatedAs($user);
            auth()->logout();
        }
    }

    public function test_a_failing_mail_provider_does_not_break_the_reset_form(): void
    {
        Notification::swap(new class extends NotificationFake
        {
            public function send($notifiables, $notification): void
            {
                throw new \RuntimeException('SMTP caído');
            }

            public function sendNow($notifiables, $notification, ?array $channels = null): void
            {
                throw new \RuntimeException('SMTP caído');
            }
        });

        $this->post('/forgot-password', ['email' => $this->a['admin']->email])->assertRedirect()->assertSessionHas('status');
    }

    public function test_revoking_a_building_or_deactivating_the_account_cuts_access_immediately(): void
    {
        [$user, $token] = $this->invite();
        $this->activate($user, $token);
        $note = DeliveryNote::factory()->create(['building_id' => $this->authorized->id, 'user_id' => $this->a['technician']->id]);
        $note->shareWithClient(true);

        $this->actingAs($user)->get(route('portal.delivery-note', $note))->assertOk();

        // Se le quita el edificio: la URL conocida deja de funcionar.
        $this->manager()->callTableAction('editBuildings', $user, data: ['building_ids' => [$this->other->id]]);
        $this->actingAs($user)->get(route('portal.delivery-note', $note))->assertNotFound();
        $this->get(route('portal.building', $this->authorized))->assertNotFound();

        // Desactivado con la sesión abierta: la sesión deja de servir.
        $this->manager()->callTableAction('editBuildings', $user, data: ['building_ids' => [$this->authorized->id]]);
        auth()->logout();
        $this->app['auth']->forgetGuards();
        $this->post('/login', ['email' => $user->email, 'password' => 'una-clave-larga-1']); // sesión real
        $this->get(route('portal.home'))->assertOk();

        // El admin lo desactiva desde el panel mientras el cliente tiene la sesión abierta.
        $this->manager()->callTableAction('deactivate', $user);
        $this->assertSoftDeleted('users', ['id' => $user->id]);
        $this->app['auth']->forgetGuards(); // nuevo request del cliente: el usuario se lee de nuevo de la base

        $this->get(route('portal.home'))->assertRedirect('/login');
        $this->get(route('portal.delivery-note', $note))->assertRedirect('/login');
    }

    public function test_the_client_never_reaches_the_admin_panel_or_technician_app(): void
    {
        [$user, $token] = $this->invite();
        $this->activate($user, $token);
        $this->actingAs($user->fresh());

        $this->get('/admin')->assertRedirect(route('portal.home'));
        $this->get('/admin/clients')->assertRedirect(route('portal.home'));
        $this->get("/{$this->a['company']->slug}/buildings")->assertRedirect(route('portal.home'));
        $this->get(route('notifications.index'))->assertRedirect();
        $this->post("/{$this->a['company']->slug}/delivery-notes/store", [])->assertForbidden();
    }

    public function test_technicians_cannot_manage_portal_access_or_use_the_portal(): void
    {
        $this->actingAs($this->a['technician']);
        $this->get(EditClient::getUrl(['record' => $this->client], panel: 'ascensores_app'))->assertRedirect();
        $this->get(route('portal.home'))->assertRedirect();
        $this->assertFalse($this->a['technician']->isClientUser());
    }

    public function test_initial_plan_cannot_create_or_use_portal_access(): void
    {
        [$user, $token] = $this->invite();
        $this->activate($user, $token);
        $note = DeliveryNote::factory()->create(['building_id' => $this->authorized->id, 'user_id' => $this->a['technician']->id]);
        $note->shareWithClient(true);

        $this->onPlan('inicial');

        // Panel: sin invitar, sin reactivar, sin compartir.
        $this->manager()
            ->assertTableActionHidden('createPortalUser')
            ->assertTableActionVisible('upgradeForPortal')
            ->assertSee('Profesional y Empresa');
        Livewire::test(ListDeliveryNotes::class)->assertTableColumnHidden('shared_with_client');

        // Servidor: aunque llegue el pedido, se rechaza.
        $other = DeliveryNote::factory()->create(['building_id' => $this->authorized->id, 'user_id' => $this->a['technician']->id]);
        try {
            $other->shareWithClient(true);
            $this->fail('Se pudo compartir con el plan Inicial.');
        } catch (PlanFeatureUnavailableException) {
        }
        $this->assertFalse($other->fresh()->shared_with_client);
        $this->assertSame(0, User::where('email', 'nuevo@consorcio.test')->count());

        // Portal: ninguna pantalla ni descarga, aunque la cuenta exista.
        auth()->logout();
        $this->actingAs($user->fresh());
        $this->get(route('portal.home'))->assertForbidden()->assertSee('no está disponible');
        $this->get(route('portal.delivery-note', $note))->assertForbidden();
        $this->get(route('portal.notifications'))->assertForbidden();

        // Volver a un plan con portal lo habilita de nuevo.
        $this->onPlan('profesional');
        $this->actingAs($user->fresh())->get(route('portal.home'))->assertOk(); // (fresh: nuevo request, plan sin memoizar)
        $this->onPlan('empresa');
        $this->actingAs($user->fresh())->get(route('portal.delivery-note', $note))->assertOk();
    }

    public function test_portal_users_do_not_use_technician_seats(): void
    {
        $this->onPlan('profesional');
        foreach (range(1, 12) as $i) {
            $u = User::factory()->create();
            $u->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->client->id])->save();
        }

        $this->assertSame(1, PlanLimit::Technicians->usage($this->a['company']));
    }
}
