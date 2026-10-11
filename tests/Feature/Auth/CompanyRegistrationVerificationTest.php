<?php

namespace Tests\Feature\Auth;

use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Listeners\NotifyNewCompanyRegistration;
use App\Models\Company;
use App\Models\User;
use App\Notifications\NewCompanyRegisteredNotification;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Registro de una empresa: correo de confirmación (solo al que se registra),
 * enlace seguro y temporal, reenvío limitado, aviso al equipo de Ascento sin
 * duplicados y estado visible para el SuperAdmin. Nada de esto bloquea el uso.
 */
class CompanyRegistrationVerificationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->super = User::factory()->create(['is_super_admin' => true]);
        config(['app.admin_notification_email' => 'equipo@ascento.test']);
    }

    private function register(array $overrides = [])
    {
        return $this->post('/register', [
            'company_name' => 'Ascensores del Sur', 'name' => 'Dueña Sur', 'email' => 'duena@sur.test',
            'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123',
            'cuit' => '30-71234567-1', 'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora', 'terms' => '1',
            ...$overrides,
        ]);
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())]);
    }

    public function test_registering_sends_the_confirmation_and_alerts_the_ascento_team_once(): void
    {
        Notification::fake();

        $this->register()->assertRedirect();
        $owner = User::where('email', 'duena@sur.test')->sole();
        $company = $owner->company;

        $this->assertFalse($owner->hasVerifiedEmail());
        Notification::assertSentTo($owner, VerifyEmail::class);

        Notification::assertSentOnDemand(NewCompanyRegisteredNotification::class, function ($n, $channels, AnonymousNotifiable $to) use ($company) {
            $mail = $n->toMail($to);
            $text = implode("\n", $mail->introLines);

            return $to->routes['mail'] === 'equipo@ascento.test' && $channels === ['mail']
                && str_contains($mail->subject, 'Ascensores del Sur')
                && str_contains($text, 'Dueña Sur') && str_contains($text, 'duena@sur.test')
                && str_contains($text, 'Pendiente de confirmar') && str_contains($text, 'Fecha y hora')
                && $mail->actionUrl === CompanyResource::getUrl('view', ['record' => $company], panel: 'ascensores_app')
                && ! str_contains($text.$mail->actionUrl, 'clave-segura-123');
        });
        Notification::assertSentTo($this->super, NewCompanyRegisteredNotification::class);

        // Repetir el aviso (reintento) no duplica.
        NotifyNewCompanyRegistration::notify($owner->id);
        Notification::assertSentOnDemandTimes(NewCompanyRegisteredNotification::class, 1);
        Notification::assertSentToTimes($this->super, NewCompanyRegisteredNotification::class, 1);
    }

    public function test_without_a_configured_address_the_registration_still_works_and_only_superadmins_hear(): void
    {
        Notification::fake();
        config(['app.admin_notification_email' => null]);

        $this->register()->assertRedirect();

        Notification::assertNothingSentTo(new AnonymousNotifiable);
        Notification::assertSentOnDemandTimes(NewCompanyRegisteredNotification::class, 0);
        Notification::assertSentTo($this->super, NewCompanyRegisteredNotification::class);
        $this->assertSame(1, Company::count());
    }

    public function test_technicians_and_portal_users_do_not_get_the_registration_email(): void
    {
        Notification::fake();
        $a = $this->makeTenant();

        $this->actingAs($a['admin']);
        $tech = User::factory()->technician()->create(['company_id' => $a['company']->id, 'email_verified_at' => null]);

        Notification::assertNotSentTo($tech, VerifyEmail::class);
        Notification::assertNotSentTo($a['admin'], VerifyEmail::class);
        Notification::assertSentOnDemandTimes(NewCompanyRegisteredNotification::class, 0);
    }

    public function test_the_link_verifies_only_its_owner_and_expired_or_tampered_links_offer_a_new_one(): void
    {
        $this->register();
        $owner = User::where('email', 'duena@sur.test')->sole();
        auth()->logout();
        $other = $this->makeTenant()['admin'];
        $other->forceFill(['email_verified_at' => null])->save();

        // Enlace de otra persona: no verifica a nadie.
        $this->actingAs($other)->get($this->verificationUrl($owner))->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
        $this->assertFalse($other->fresh()->hasVerifiedEmail());

        // Vencido o alterado: pantalla para pedir otro, sin verificar.
        $this->actingAs($owner)->get($this->verificationUrl($owner, -5))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()->assertSee('El enlace venció o no es válido');
        $this->get(str_replace('signature=', 'signature=0', $this->verificationUrl($owner)))->assertRedirect(route('verification.notice'));
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());

        // El correcto: verifica y vuelve al panel con el aviso.
        $this->get($this->verificationUrl($owner))->assertRedirect();
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());

        // Volver a usarlo no rompe nada (ya está verificado).
        $this->get($this->verificationUrl($owner))->assertRedirect();

        // Cambiar el correo invalida los enlaces del correo anterior.
        $old = $this->verificationUrl($owner);
        $owner->forceFill(['email' => 'nuevo@sur.test', 'email_verified_at' => null])->save();
        $this->actingAs($owner->fresh())->get($old)->assertForbidden();
        $this->assertFalse($owner->fresh()->hasVerifiedEmail());
    }

    public function test_resending_is_rate_limited_and_skipped_for_verified_users(): void
    {
        Notification::fake();
        $this->register();
        $owner = User::where('email', 'duena@sur.test')->sole();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($owner)->post(route('verification.send'))->assertSessionHas('status', 'verification-link-sent');
        }
        $this->post(route('verification.send'))->assertStatus(429);
        Notification::assertSentToTimes($owner, VerifyEmail::class, 7); // 1 del registro + 6 reenvíos

        auth()->logout();
        $verified = $this->makeTenant()['admin'];
        $this->actingAs($verified)->post(route('verification.send'))->assertRedirect();
        Notification::assertNotSentTo($verified, VerifyEmail::class);
    }

    public function test_an_unverified_admin_keeps_working_and_sees_how_to_confirm(): void
    {
        $a = $this->makeTenant();
        $a['admin']->forceFill(['email_verified_at' => null])->save();

        $this->actingInPanel($a['admin'])->get('/admin/dashboard')->assertOk()
            ->assertSee('Confirmá tu correo')->assertSee(route('verification.send'), false);

        $a['admin']->forceFill(['email_verified_at' => now()])->save();
        $this->actingInPanel($a['admin']->fresh())->get('/admin/dashboard')->assertOk()->assertDontSee('Confirmá tu correo');
        $this->get('/admin/dashboard?verified=1')->assertSee('Correo confirmado');

        // Técnico sin verificar: sigue usando la app.
        $a['technician']->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($a['technician']->fresh())->get(route('dashboard', ['company' => $a['company']->slug]))->assertOk();
    }

    public function test_the_superadmin_sees_the_separate_states_and_company_admins_cannot(): void
    {
        $this->register();
        $owner = User::where('email', 'duena@sur.test')->sole();
        $company = $owner->company;
        auth()->logout(); // si no, la empresa nueva de prueba quedaría dentro de la de la dueña
        $verifiedCompany = $this->makeTenant();
        $this->flushSession(); // la sesión del registro guarda la huella de otra contraseña

        $this->actingInPanel($this->super)->get(CompanyResource::getUrl('view', ['record' => $company]))->assertOk()
            ->assertSee('Registrada')->assertSee('Sin confirmar')->assertSee('Habilitada')->assertSee('Prueba gratis')
            ->assertSee('Dueña Sur')->assertSee('datos reales de la base');

        Livewire::test(ListCompanies::class)
            ->filterTable('email_verification', 'unverified')
            ->assertCanSeeTableRecords([$company])->assertCanNotSeeTableRecords([$verifiedCompany['company']]);

        $this->actingInPanel($verifiedCompany['admin'])->get(CompanyResource::getUrl('view', ['record' => $company]))->assertNotFound();
    }
}
