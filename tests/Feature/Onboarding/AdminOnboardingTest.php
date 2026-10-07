<?php

namespace Tests\Feature\Onboarding;

use App\Livewire\AdminOnboarding;
use App\Models\Client;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class AdminOnboardingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function freshAdmin(): User
    {
        $tenant = $this->makeTenant();

        return $tenant['admin']->fresh();
    }

    public function test_first_visit_starts_the_guide_and_shows_help_button(): void
    {
        $admin = $this->freshAdmin();

        $this->actingAs($admin)
            ->get('/admin/company-settings')
            ->assertOk()
            ->assertSee('data-autostart="true"', false)
            ->assertSee('data-ascento-help', false)
            ->assertSee('Bienvenido a Ascento 👋');
    }

    public function test_admin_can_complete_the_guide_and_it_does_not_auto_start_again(): void
    {
        $admin = $this->freshAdmin();

        $this->actingInPanel($admin);
        Livewire::test(AdminOnboarding::class)->call('complete');

        $this->assertNotNull($admin->fresh()->onboarding_completed_at);

        $this->actingAs($admin->fresh())
            ->get('/admin/company-settings')
            ->assertOk()
            ->assertSee('data-autostart="false"', false)
            // La ayuda sigue disponible.
            ->assertSee('data-ascento-help', false);
    }

    public function test_admin_can_skip_the_guide(): void
    {
        $admin = $this->freshAdmin();

        $this->actingInPanel($admin);
        Livewire::test(AdminOnboarding::class)->call('skip');

        $admin->refresh();
        $this->assertNotNull($admin->onboarding_skipped_at);
        $this->assertNull($admin->onboarding_completed_at);
        $this->assertFalse($admin->shouldAutoStartOnboarding());
    }

    public function test_guide_can_be_reopened_from_help_without_resetting_state(): void
    {
        $admin = $this->freshAdmin();
        $admin->forceFill(['onboarding_completed_at' => now()])->save();

        $this->actingInPanel($admin);

        // Reabrir es 100% del lado del navegador (no guarda nada): el botón
        // de Ayuda arranca el recorrido sin tocar el estado guardado.
        Livewire::test(AdminOnboarding::class)
            ->assertSee('Ver la guía desde el principio')
            ->assertSeeHtml('x-on:click="start(0)" data-ascento-restart');

        $this->assertNotNull($admin->fresh()->onboarding_completed_at);
        $this->assertFalse($admin->fresh()->shouldAutoStartOnboarding());
    }

    public function test_state_is_per_admin_and_never_crosses_companies(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $otherAdminA = User::factory()->admin()->create(['company_id' => $a['company']->id]);

        $this->actingInPanel($a['admin']);
        Livewire::test(AdminOnboarding::class)->call('complete');

        $this->assertNotNull($a['admin']->fresh()->onboarding_completed_at);
        $this->assertNull($b['admin']->fresh()->onboarding_completed_at);
        $this->assertNull($otherAdminA->fresh()->onboarding_completed_at);

        $this->actingAs($b['admin']->fresh())
            ->get('/admin/company-settings')
            ->assertSee('data-autostart="true"', false);
    }

    public function test_checklist_uses_only_the_admin_company_data(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin']);
        $checklistB = collect(Livewire::test(AdminOnboarding::class)->viewData('checklist'))->keyBy('label');

        $this->assertTrue($checklistB['Creá tu primer cliente']['done']);
        $this->assertTrue($checklistB['Asigná un técnico a un edificio']['done']);
        $this->assertFalse($checklistB['Creá tu primera orden de trabajo (opcional)']['done']);

        // Una empresa nueva, sin datos propios, no "hereda" el progreso de otra.
        // (Se crea como invitado: logueado como B, el modelo la forzaría a B.)
        $this->app['auth']->forgetGuards();
        $empty = Company::factory()->create();
        $emptyAdmin = User::factory()->admin()->create(['company_id' => $empty->id]);

        $this->actingInPanel($emptyAdmin);
        $checklist = collect(Livewire::test(AdminOnboarding::class)->viewData('checklist'));

        $this->assertTrue($checklist->every(fn ($item) => $item['done'] === false));
        $this->assertSame(2, Client::withoutGlobalScopes()->count());
    }

    public function test_every_step_points_to_a_real_sidebar_item(): void
    {
        $admin = $this->freshAdmin();
        $this->actingInPanel($admin);

        $html = $this->get('/admin/company-settings')->assertOk()->getContent();

        foreach (AdminOnboarding::steps() as $step) {
            if ($step['target'] !== null) {
                $this->assertStringContainsString('href="'.$step['target'].'"', $html, "El paso '{$step['key']}' apunta a algo que no está en el menú.");
            }
        }
    }

    public function test_contextual_help_matches_the_current_section(): void
    {
        $admin = $this->freshAdmin();
        $this->actingInPanel($admin);

        Livewire::test(AdminOnboarding::class, ['routeName' => 'filament.ascensores_app.resources.clients.index'])
            ->assertSet('section', 'clients')
            ->assertSee('Primero creá el cliente');

        $this->actingAs($admin)
            ->get('/admin/buildings')
            ->assertOk()
            ->assertSee('Asignar empleado');
    }

    public function test_guide_does_not_auto_start_when_company_has_no_access(): void
    {
        $tenant = $this->makeTenant();
        $tenant['company']->update(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($tenant['admin']->fresh())
            ->get('/admin/subscription')
            ->assertOk()
            ->assertSee('data-autostart="false"', false);
    }

    public function test_super_admin_is_not_affected(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get('/admin/companies')
            ->assertOk()
            ->assertDontSee('data-ascento-help', false)
            ->assertDontSee('asc-onb', false);

        $this->actingInPanel($superAdmin);
        Livewire::test(AdminOnboarding::class)->assertForbidden();
    }

    public function test_technicians_cannot_use_the_component(): void
    {
        $tenant = $this->makeTenant();

        $this->actingInPanel($tenant['technician']);
        Livewire::test(AdminOnboarding::class)->assertForbidden();
    }

    public function test_onboarding_state_is_not_mass_assignable(): void
    {
        $admin = $this->freshAdmin();

        $admin->fill([
            'onboarding_completed_at' => now(),
            'onboarding_skipped_at' => now(),
        ])->save();

        $admin->refresh();
        $this->assertNull($admin->onboarding_completed_at);
        $this->assertNull($admin->onboarding_skipped_at);
    }
}
