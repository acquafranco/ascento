<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\CompanySetupWizard;
use App\Filament\Pages\Dashboard;
use App\Models\Company;
use App\Models\Elevator;
use App\Models\User;
use App\Services\Onboarding\CompanySetup;
use App\Support\Auth\EmailGate;
use Carbon\Carbon;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * El dueño de una empresa nueva hace la configuración inicial y, al
 * terminarla, no entra al panel hasta confirmar su correo («Te mandamos un
 * correo para verificar tu cuenta»). No afecta a técnicos, administrativos,
 * portal, SuperAdmin ni a empresas anteriores a la fecha de corte.
 */
class OwnerEmailVerificationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Notification::fake();
        $this->travelTo(Carbon::parse('2026-10-22 10:00'));
        config(['auth.verified_owner_since' => '2026-10-21']);
    }

    /** Empresa registrada hoy, con la configuración inicial pendiente y el dueño sin confirmar. */
    private function newCompany(string $email = 'duena@nuevos.test'): array
    {
        $company = Company::factory()->create(['name' => 'Ascensores Nuevos']);
        $company->forceFill(['setup_status' => CompanySetup::PENDING])->save();
        $owner = User::factory()->admin()->unverified()->create(['company_id' => $company->id, 'email' => $email]);

        return [$company->fresh(), $owner->fresh()];
    }

    private function walkTheWizard(): Testable
    {
        return Livewire::test(CompanySetupWizard::class)
            ->call('next')
            ->set('companyData.name', 'Ascensores del Sur')->set('companyData.phone', '011 4000-1000')->set('companyData.email', 'hola@sur.test')
            ->call('next')->set('brandData.primary_color', '#0E7490')
            ->call('next')
            ->fillForm(['name' => 'Juan Técnico', 'email' => 'juan@nuevos.test', 'phone' => '11 2345-6789', 'password' => 'Kiosco-Verde-8241', 'password_confirmation' => 'Kiosco-Verde-8241'], 'technicianForm')
            ->call('next')->fillForm(['name' => 'Consorcio Rivadavia', 'type' => 'consorcio'], 'clientForm')
            ->call('next')->fillForm(['name' => 'Rivadavia', 'address' => '4000', 'locality' => 'Caballito', 'equipment' => [
                'a' => ['id' => null, 'label' => null, 'kind' => 'elevator', 'machine_type' => array_key_first(Elevator::MACHINE_TYPES)],
            ]], 'buildingForm')
            ->call('next')->fillForm(['unit' => 'Ascensor 1', 'type' => 'claim', 'priority' => 'high', 'notes' => 'Ruido'], 'orderForm')
            ->call('next')->assertHasNoErrors()->assertSet('screen', 'done');
    }

    public function test_the_guide_works_and_its_last_screen_asks_to_confirm_the_email_without_leaving(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);

        // Durante la configuración inicial, el panel lleva a la guía (no a «Verificá tu email»).
        $this->get(Dashboard::getUrl())->assertRedirect(CompanySetupWizard::getUrl());
        $this->get(CompanySetupWizard::getUrl())->assertOk();

        $wizard = $this->walkTheWizard()
            ->assertSee('Último paso: confirmá tu correo')
            ->assertSee('Te mandamos un correo a')->assertSee('duena@nuevos.test')
            ->assertSee('Ya confirmé mi correo');

        // «Ya confirmé» sin haberlo confirmado: no sale de la pantalla ni termina la configuración.
        $wizard->call('finish')->assertNotified('Todavía no confirmaste tu correo')->assertNoRedirect()->assertSet('screen', 'done');
        $this->assertSame(CompanySetup::PENDING, $company->fresh()->setup_status);
        $this->get(Dashboard::getUrl())->assertRedirect(CompanySetupWizard::getUrl());

        // Pide otro enlace: llega; después de 3 en 10 minutos, espera.
        $wizard->call('resendVerification')->assertNotified('Te mandamos un enlace nuevo');
        Notification::assertSentTo($owner, VerifyEmail::class);
        $wizard->call('resendVerification')->call('resendVerification')->call('resendVerification')->assertNotified('Ya te mandamos varios enlaces hace poco');

        // Confirma desde el enlace del correo: vuelve a la guía, ahora con «Ir al inicio».
        $owner->markEmailAsVerified();
        $this->actingInPanel($owner->fresh()); // nueva request: el usuario se lee de la base
        Livewire::test(CompanySetupWizard::class)->assertSet('screen', 'done')->assertSee('Correo confirmado')->assertSee('Ir al inicio')
            ->call('finish')->assertRedirect(Dashboard::getUrl().'?configuracion=lista');
        $this->assertSame(CompanySetup::COMPLETED, $company->fresh()->setup_status);
        $this->actingInPanel($owner->fresh());
        $this->get(Dashboard::getUrl())->assertOk();
    }

    public function test_pausing_or_skipping_the_guide_leads_to_verify_email_and_nothing_else_opens(): void
    {
        [$company, $owner] = $this->newCompany();
        $this->actingInPanel($owner);

        // «Seguir después» (queda pausada en la sesión).
        Livewire::test(CompanySetupWizard::class)->call('pause');
        $paused = [CompanySetup::PAUSED_SESSION_KEY => true];
        $this->withSession($paused)->get(Dashboard::getUrl())->assertRedirect(route('verification.notice'));
        $this->withSession($paused)->get('/admin/clients')->assertRedirect(route('verification.notice'));
        $this->withSession($paused)->get("/{$company->slug}/dashboard")->assertRedirect(route('verification.notice'));

        $this->get(route('verification.notice'))->assertOk()
            ->assertSee('Verificá tu email')->assertSee('duena@nuevos.test')
            ->assertSee('hasta entonces no se puede entrar al panel')
            ->assertSee('¿Escribiste mal tu correo?')->assertSee('Reenviar email de verificación');

        // Omitir la guía tampoco abre el panel.
        $this->flushSession();
        $this->actingInPanel($owner->fresh());
        Livewire::test(CompanySetupWizard::class)->call('skip');
        $this->assertSame(CompanySetup::SKIPPED, $company->fresh()->setup_status);
        $this->actingInPanel($owner->fresh()); // nueva request: la empresa se lee de nuevo
        $this->get(Dashboard::getUrl())->assertRedirect(route('verification.notice'));

        // Confirmado (el enlace del correo): entra.
        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $owner->id, 'hash' => sha1($owner->email)]);
        $this->get($url)->assertRedirect();
        $this->assertTrue($owner->fresh()->hasVerifiedEmail());
        $this->get(Dashboard::getUrl())->assertOk();
    }

    public function test_a_mistyped_email_can_be_corrected_from_the_verify_screen(): void
    {
        [$company, $owner] = $this->newCompany();
        $company->forceFill(['email' => 'duena@nuevos.test'])->save();
        $taken = User::factory()->technician()->create(['email' => 'tomado@otra.test']);
        $this->actingAs($owner);

        // Un correo de otra cuenta: no (un correo = una cuenta), sin decir de quién es.
        $this->from(route('verification.notice'))->post(route('verification.change-email'), ['email' => 'TOMADO@otra.test'])
            ->assertSessionHasErrors('email');
        $this->assertSame('duena@nuevos.test', $owner->fresh()->email);

        $this->from(route('verification.notice'))->post(route('verification.change-email'), ['email' => ' Duena.Bien@Nuevos.test '])
            ->assertRedirect(route('verification.notice'))->assertSessionHas('status', 'verification-email-changed');
        $this->assertSame(['duena.bien@nuevos.test', null], [$owner->fresh()->email, $owner->fresh()->email_verified_at]);
        $this->assertSame('duena.bien@nuevos.test', $company->fresh()->email); // el contacto de la empresa era el mismo
        Notification::assertSentTo($owner->fresh(), VerifyEmail::class);

        // Ya confirmado: no se usa este atajo (el cambio de correo sigue su camino normal).
        $owner->fresh()->markEmailAsVerified();
        $this->actingAs($owner->fresh())->post(route('verification.change-email'), ['email' => 'otro@nuevos.test'])->assertRedirect();
        $this->assertSame('duena.bien@nuevos.test', $owner->fresh()->email);
    }

    public function test_nobody_else_is_blocked(): void
    {
        // Empresa anterior a la fecha de corte, dueño sin confirmar: sigue entrando.
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        [$oldCompany, $oldOwner] = $this->newCompany('vieja@empresa.test');
        $oldCompany->forceFill(['setup_status' => CompanySetup::COMPLETED])->save();
        $this->travelTo(Carbon::parse('2026-10-22 10:00'));
        $this->actingInPanel($oldOwner->fresh());
        $this->get(Dashboard::getUrl())->assertOk();
        $this->assertFalse(EmailGate::appliesTo($oldOwner->fresh()));

        // Empresa creada sin la configuración inicial (por ejemplo, por el SuperAdmin): no aplica.
        auth()->logout(); // (con la sesión abierta, la empresa nueva se crearía dentro de la anterior)
        $tenant = $this->makeTenant();
        $tenant['admin']->forceFill(['email_verified_at' => null])->save();
        $this->flushSession();
        $this->actingInPanel($tenant['admin']->fresh());
        $this->get(Dashboard::getUrl())->assertOk();

        // Técnico de una empresa nueva, sin confirmar: usa su app.
        auth()->logout();
        [$company] = $this->newCompany();
        $company->forceFill(['setup_status' => CompanySetup::COMPLETED])->save();
        $tech = User::factory()->technician()->unverified()->create(['company_id' => $company->id]);
        $this->flushSession();
        $this->actingAs($tech)->get("/{$company->slug}/dashboard")->assertOk();
        $this->assertFalse(EmailGate::blocks($tech));

        // Con la fecha de corte vacía, la función está apagada.
        config(['auth.verified_owner_since' => null]);
        $this->assertFalse(EmailGate::appliesTo(User::where('company_id', $company->id)->where('role', 'admin')->first()));
    }
}
