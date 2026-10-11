<?php

namespace Tests\Feature\Auth;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Building;
use App\Models\Client;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Confirmación del correo de técnicos y clientes del portal (los admins ya la
 * tenían): sin bloquear el trabajo, con avisos claros y solo con una prueba
 * válida de que el correo es suyo.
 */
class TechnicianEmailVerificationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->a = $this->makeTenant();
    }

    private function link(User $user, ?string $email = null, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($email ?? $user->getEmailForVerification())]);
    }

    private function unverifiedTechnician(): User
    {
        $tech = $this->a['technician'];
        $tech->forceFill(['email_verified_at' => null])->save();

        return $tech->fresh();
    }

    public function test_a_new_technician_receives_the_link_and_starts_unverified(): void
    {
        Notification::fake();
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Raúl Técnico', 'email' => 'raul@empresa.test', 'phone' => '1122334455',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241',
        ])->call('create')->assertHasNoFormErrors();

        $tech = User::where('email', 'raul@empresa.test')->sole();
        $this->assertFalse($tech->hasVerifiedEmail());
        Notification::assertSentToTimes($tech, VerifyEmail::class, 1);
        Notification::assertNotSentTo($this->a['admin'], VerifyEmail::class);
    }

    public function test_if_the_mail_fails_the_technician_is_created_anyway(): void
    {
        config(['mail.default' => 'roto', 'mail.mailers.roto' => ['transport' => 'no-existe']]);
        Log::spy();
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CreateUser::class)->fillForm([
            'name' => 'Sin Correo', 'email' => 'sincorreo@empresa.test', 'phone' => '1122334455',
            'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241',
        ])->call('create')->assertHasNoFormErrors();

        $this->assertTrue(User::where('email', 'sincorreo@empresa.test')->exists());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'No se pudo mandar el correo de confirmación');
    }

    public function test_an_unverified_technician_keeps_working_and_sees_how_to_confirm(): void
    {
        $tech = $this->unverifiedTechnician();
        $slug = $this->a['company']->slug;

        $this->actingAs($tech)->get("/{$slug}/dashboard")->assertOk()
            ->assertSee('data-verify-email-notice', false)
            ->assertSee('Podés seguir usando Ascento')
            ->assertSee(route('verification.send'), false);
        $this->get("/{$slug}/buildings")->assertOk();
        $this->get(route('notifications.index'))->assertOk();

        // Confirmado: el aviso desaparece.
        $tech->markEmailAsVerified();
        $this->get("/{$slug}/dashboard")->assertOk()->assertDontSee('data-verify-email-notice', false);
    }

    public function test_the_link_confirms_only_with_a_valid_proof(): void
    {
        $tech = $this->unverifiedTechnician();
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id, 'email_verified_at' => null]);

        // El enlace de otra persona no confirma la propia ni la ajena.
        $this->actingAs($tech)->get($this->link($other))->assertForbidden();
        $this->assertFalse($tech->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());

        // Enlace manipulado (sin firma válida): no confirma.
        $this->get(str_replace('signature=', 'signature=x', $this->link($tech)))->assertRedirect(route('verification.notice'));
        $this->assertFalse($tech->fresh()->hasVerifiedEmail());

        // El suyo sí, y vuelve a la app con el "listo".
        $this->get($this->link($tech))->assertRedirect();
        $this->assertTrue($tech->fresh()->hasVerifiedEmail());
        $this->get('/'.$this->a['company']->slug.'/dashboard?verified=1')->assertSee('Correo confirmado.');
    }

    public function test_an_expired_link_asks_for_a_new_one(): void
    {
        $tech = $this->unverifiedTechnician();
        $url = $this->link($tech, minutes: 60);
        $this->travel(61)->minutes();

        $this->actingAs($tech)->get($url)->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-link-expired');
        $this->assertFalse($tech->fresh()->hasVerifiedEmail());
    }

    public function test_changing_the_email_requires_confirming_the_new_one_and_old_links_stop_working(): void
    {
        Notification::fake();
        $tech = $this->a['technician'];
        $tech->update(['phone' => '1122334455']);
        $this->assertTrue($tech->fresh()->hasVerifiedEmail());
        $oldLink = $this->link($tech->fresh());

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditUser::class, ['record' => $tech->getRouteKey()])->fillForm(['email' => 'nuevo-correo@empresa.test'])
            ->call('save')->assertHasNoFormErrors();

        $tech->refresh();
        $this->assertSame('nuevo-correo@empresa.test', $tech->email);
        $this->assertFalse($tech->hasVerifiedEmail());
        Notification::assertSentToTimes($tech, VerifyEmail::class, 1);

        // El enlace del correo anterior ya no sirve para el nuevo.
        $this->actingAs($tech)->get($oldLink)->assertForbidden();
        $this->assertFalse($tech->fresh()->hasVerifiedEmail());
    }

    public function test_resending_is_limited_for_technicians_too(): void
    {
        Notification::fake();
        $tech = $this->unverifiedTechnician();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($tech)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        }
        $this->post(route('verification.send'))->assertSessionHas('status', 'verification-throttled');
        Notification::assertSentToTimes($tech, VerifyEmail::class, 3);
    }

    public function test_resetting_the_password_from_the_email_link_confirms_the_email(): void
    {
        Notification::fake();
        $tech = $this->unverifiedTechnician();
        $this->post('/forgot-password', ['email' => $tech->email]);
        $token = null;
        Notification::assertSentTo($tech, ResetPasswordNotification::class, function ($n) use (&$token) {
            $token = (fn () => $this->token)->call($n);

            return true;
        });

        // Un intento fallido (token equivocado) no confirma nada.
        $this->post('/reset-password', ['token' => 'falso', 'email' => $tech->email, 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241']);
        $this->assertFalse($tech->fresh()->hasVerifiedEmail());

        $this->post('/reset-password', ['token' => $token, 'email' => $tech->email, 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($tech->fresh()->hasVerifiedEmail());
    }

    public function test_portal_clients_see_the_notice_only_while_unverified(): void
    {
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $building = Building::factory()->create(['company_id' => $this->a['company']->id, 'client_id' => $client->id]);
        $portal = User::factory()->create(['email' => 'cliente@consorcio.test', 'email_verified_at' => null]);
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $client->company_id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$building->id]);

        $this->actingAs($portal)->get(route('portal.home'))->assertOk()->assertSee('data-verify-email-notice', false);

        $portal->markEmailAsVerified();
        $this->get(route('portal.home'))->assertOk()->assertDontSee('data-verify-email-notice', false);
    }
}
