<?php

namespace Tests\Feature\Stage11;

use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use App\Notifications\ConfirmEmailChangeNotification;
use App\Notifications\EmailChangeRequestedNotification;
use App\Notifications\ResetPasswordNotification;
use App\Services\Auth\PendingEmailChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Etapa 11: el correo nuevo queda pendiente hasta que se confirma desde esa
 * dirección. Hasta entonces el anterior sigue siendo el de ingreso y el de
 * recuperación, así que un correo pendiente no sirve para tomar la cuenta.
 */
class PendingEmailChangeTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->a = $this->makeTenant();
        $this->tech = $this->a['technician'];
        $this->tech->forceFill(['email' => 'viejo@empresa.test', 'email_verified_at' => now()->subDay(), 'password' => Hash::make('clave-actual-123'), 'phone' => '+5491155550001'])->saveQuietly();
        $this->a['admin']->forceFill(['phone' => '+5491155550002'])->saveQuietly();
        $this->tech = $this->tech->fresh();
        Notification::fake(); // sin los avisos de la preparación
    }

    private function profileUrl(): string
    {
        return '/'.$this->a['company']->slug.'/profile';
    }

    private function requestChange(string $email = 'nuevo@empresa.test'): TestResponse
    {
        $this->actingAs($this->tech->fresh());

        return $this->from($this->profileUrl())->patch($this->profileUrl(), ['name' => $this->tech->name, 'email' => $email, 'current_password' => 'clave-actual-123']);
    }

    /** El enlace que se mandó al correo nuevo. */
    private function confirmationUrl(string $to = 'nuevo@empresa.test'): string
    {
        $url = null;
        Notification::assertSentOnDemand(ConfirmEmailChangeNotification::class, function ($notification, $channels, $notifiable) use ($to, &$url) {
            if (($notifiable->routes['mail'] ?? null) === $to) {
                $url = $notification->url;

                return true;
            }

            return false;
        });

        return $url;
    }

    private function resetLinksSent(): int
    {
        return Notification::sent($this->tech, ResetPasswordNotification::class)->count();
    }

    public function test_the_new_email_stays_pending_and_the_old_one_keeps_login_and_recovery(): void
    {
        $this->requestChange()->assertSessionHasNoErrors();

        $user = $this->tech->fresh();
        $this->assertSame('viejo@empresa.test', $user->email);
        $this->assertNotNull($user->email_verified_at, 'El correo actual sigue confirmado.');
        $this->assertSame('nuevo@empresa.test', $user->pending_email);
        $this->assertNotSame('', (string) $user->pending_email_token);
        $this->assertStringNotContainsString($user->pending_email_token, $this->confirmationUrl(), 'En la base queda solo el hash del token.');
        // Aviso al correo actual, por si no fue la persona.
        Notification::assertSentTo($user, EmailChangeRequestedNotification::class);
        // El perfil muestra el pedido pendiente y cómo cancelarlo.
        $this->get($this->profileUrl())->assertOk()->assertSee('data-pending-email', false)->assertSee('nuevo@empresa.test')->assertSee('Cancelar el cambio');

        // Recuperar: con el correo pendiente no sale nada; con el actual, sí.
        auth()->logout();
        $this->flushSession();
        $this->post('/forgot-password', ['email' => 'nuevo@empresa.test']);
        $this->assertSame(0, $this->resetLinksSent());
        $this->post('/forgot-password', ['email' => 'viejo@empresa.test']);
        $this->assertSame(1, $this->resetLinksSent());

        // Ingresar: con el actual, sí; con el pendiente, no.
        $this->post('/login', ['email' => 'nuevo@empresa.test', 'password' => 'clave-actual-123']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'viejo@empresa.test', 'password' => 'clave-actual-123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_confirming_from_the_new_address_switches_the_email_once(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();
        auth()->logout();
        $this->flushSession();

        // Se confirma abriendo el enlace del correo nuevo (aunque no haya sesión en ese dispositivo).
        $this->get($url)->assertOk()->assertSee('Listo');
        $user = $this->tech->fresh();
        $this->assertSame('nuevo@empresa.test', $user->email);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->pending_email);
        $this->assertNull($user->pending_email_token);

        // Un solo uso.
        $this->get($url)->assertStatus(410);
        $this->assertSame('nuevo@empresa.test', $user->fresh()->email);

        // Desde ahora se ingresa y se recupera con el nuevo.
        $this->post('/forgot-password', ['email' => 'nuevo@empresa.test']);
        $this->assertSame(1, $this->resetLinksSent());
    }

    public function test_expired_tampered_or_replaced_links_do_nothing(): void
    {
        $this->requestChange('primero@empresa.test');
        $first = $this->confirmationUrl('primero@empresa.test');

        // Otro pedido reemplaza al anterior: el primer enlace ya no sirve.
        $this->travel(2)->minutes();
        $this->requestChange('segundo@empresa.test');
        $second = $this->confirmationUrl('segundo@empresa.test');
        auth()->logout();
        $this->flushSession();
        $this->get($first)->assertStatus(410);
        $this->assertSame('viejo@empresa.test', $this->tech->fresh()->email);

        // Token alterado.
        $this->get(preg_replace('#/([^/]+)$#', '/'.str_repeat('a', 64), $second))->assertStatus(410);
        // Otro usuario en la URL con el mismo token.
        $this->get(preg_replace('#/confirmar/\d+/#', '/confirmar/'.$this->a['admin']->id.'/', $second))->assertStatus(410);

        // Vencido.
        $this->travel(PendingEmailChange::EXPIRES_MINUTES + 1)->minutes();
        $this->get($second)->assertStatus(410);
        $user = $this->tech->fresh();
        $this->assertSame('viejo@empresa.test', $user->email);
        $this->assertNull($user->pending_email, 'Un pedido vencido se descarta.');
    }

    public function test_cancel_and_repeated_requests(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();

        $this->actingAs($this->tech->fresh());
        $this->post(route('profile.email.cancel', ['company' => $this->a['company']->slug]))->assertRedirect();
        $this->assertNull($this->tech->fresh()->pending_email);
        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertStatus(410);

        // Pedidos repetidos: hay un tope (no se puede usar para mandar correos sin fin).
        // Cancelar no reinicia el tope; pasada la ventana, se puede volver a pedir.
        $this->travel(PendingEmailChange::WINDOW_MINUTES + 1)->minutes();
        for ($i = 0; $i < PendingEmailChange::MAX_REQUESTS; $i++) {
            $this->requestChange("otro{$i}@empresa.test")->assertSessionHasNoErrors();
        }
        $this->requestChange('uno-mas@empresa.test')->assertSessionHasErrors('email');
        $this->assertSame('otro'.(PendingEmailChange::MAX_REQUESTS - 1).'@empresa.test', $this->tech->fresh()->pending_email);
    }

    public function test_collisions_never_take_or_block_another_account(): void
    {
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => 'ocupado@empresa.test', 'password' => Hash::make('clave-ajena-123')]);

        // Un correo que ya es de otra cuenta no se puede pedir.
        $this->requestChange('ocupado@empresa.test')->assertSessionHasErrors('email');
        $this->assertNull($this->tech->fresh()->pending_email);

        // Pedir como pendiente un correo libre no lo reserva: otra cuenta puede tomarlo y,
        // si llega primero, la confirmación del pendiente se rechaza sin tocar a nadie.
        $this->requestChange('libre@empresa.test');
        $url = $this->confirmationUrl('libre@empresa.test');
        $other->forceFill(['email' => 'libre@empresa.test', 'email_verified_at' => now()])->saveQuietly();
        auth()->logout();
        $this->flushSession();
        $this->get($url)->assertStatus(409);
        $this->assertSame('viejo@empresa.test', $this->tech->fresh()->email);
        $this->assertNull($this->tech->fresh()->pending_email);
        $this->assertSame('libre@empresa.test', $other->fresh()->email);

        // Un pendiente nunca impide que el dueño de ese correo recupere su cuenta.
        $this->requestChange('ocupado2@empresa.test');
        $owner = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email' => 'ocupado2@empresa.test']);
        auth()->logout();
        $this->flushSession();
        $this->post('/forgot-password', ['email' => 'ocupado2@empresa.test']);
        Notification::assertSentTo($owner, ResetPasswordNotification::class);
    }

    public function test_the_link_cannot_be_confirmed_from_another_logged_in_account(): void
    {
        $this->requestChange();
        $url = $this->confirmationUrl();

        $this->flushSession();
        $this->actingAs($this->a['admin']->fresh());
        $this->get($url)->assertForbidden();
        $this->assertSame('viejo@empresa.test', $this->tech->fresh()->email);
        $this->assertSame('nuevo@empresa.test', $this->tech->fresh()->pending_email, 'Sigue pendiente para su dueño.');
    }

    public function test_the_owner_editing_their_own_user_in_the_panel_goes_through_the_same_flow(): void
    {
        $owner = $this->a['admin'];
        $owner->forceFill(['email' => 'duenio@empresa.test', 'email_verified_at' => now()->subDay(), 'password' => Hash::make('clave-duenio-123')])->saveQuietly();
        Notification::fake();
        $this->actingAs($owner->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        // Sin la contraseña actual: ni el correo ni la contraseña cambian.
        Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])
            ->fillForm(['email' => 'atacante@ejemplo.test', 'password' => 'otra-clave-segura-456', 'password_confirmation' => 'otra-clave-segura-456'])
            ->call('save')->assertHasFormErrors(['current_password']);
        $this->assertSame('duenio@empresa.test', $owner->fresh()->email);
        $this->assertTrue(Hash::check('clave-duenio-123', $owner->fresh()->password));

        // Con la contraseña: el correo queda pendiente (no cambia todavía).
        Livewire::test(EditUser::class, ['record' => $owner->getRouteKey()])
            ->fillForm(['email' => 'nuevo-duenio@empresa.test', 'current_password' => 'clave-duenio-123'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('duenio@empresa.test', $owner->fresh()->email);
        $this->assertSame('nuevo-duenio@empresa.test', $owner->fresh()->pending_email);
    }

    public function test_the_owner_still_manages_the_email_of_a_technician_and_existing_users_are_untouched(): void
    {
        // El dueño corrige el correo de un técnico (cuenta de su empresa): como hasta ahora,
        // cambia en el acto, queda sin confirmar y le llega el enlace de confirmación.
        $this->actingAs($this->a['admin']->fresh());
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        Livewire::test(EditUser::class, ['record' => $this->tech->getRouteKey()])
            ->fillForm(['email' => 'corregido@empresa.test'])
            ->call('save')->assertHasNoFormErrors();
        $this->assertSame('corregido@empresa.test', $this->tech->fresh()->email);
        $this->assertNull($this->tech->fresh()->pending_email);

        // Los usuarios existentes no tienen nada pendiente y siguen entrando igual.
        $this->assertSame(0, User::whereNotNull('pending_email')->count());
    }

    /*
    | Si el correo de confirmación no sale (SMTP caído o mal configurado), el
    | pedido no queda a medias: no hay pendiente, el usuario ve un error claro
    | y puede volver a intentar. El token nunca aparece en el log.
    */
    public function test_a_failed_confirmation_email_leaves_nothing_pending_and_can_be_retried(): void
    {
        Notification::swap(new ChannelManager(app())); // envío real, sin fake
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 2]);
        $logged = [];
        Log::listen(function ($m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context);
        });

        $this->requestChange()->assertSessionHasErrors('email');
        $this->assertStringContainsString('No pudimos mandar el correo de confirmación', session('errors')->first('email'));
        $user = $this->tech->fresh();
        $this->assertSame('viejo@empresa.test', $user->email);
        $this->assertNull($user->pending_email, 'Sin correo enviado no queda nada pendiente.');
        $this->assertNull($user->pending_email_token);
        $this->assertStringNotContainsString('correo/confirmar', implode("\n", $logged), 'El enlace (con su token) no va al log.');

        // Con el correo funcionando, el mismo pedido sale bien.
        Notification::fake();
        $this->requestChange()->assertSessionHasNoErrors();
        $this->assertSame('nuevo@empresa.test', $this->tech->fresh()->pending_email);
    }
}
