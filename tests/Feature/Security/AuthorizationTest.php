<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\CompanyOverview;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Filament\Resources\Companies\CompanyResource;
use App\Filament\Resources\Companies\Pages\ListCompanies;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Roles reales de Ascento:
 * - SuperAdmin (is_super_admin, sin empresa): administra empresas en /admin.
 * - Admin de empresa (role=admin): panel /admin de SU empresa.
 * - Técnico (role=technician): app web /{empresa}/..., sin panel.
 */
class AuthorizationTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_technician_cannot_access_admin_panel(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])->get('/admin')->assertForbidden();
        $this->actingAs($a['technician'])->get('/admin/users')->assertForbidden();
        $this->actingAs($a['technician'])->get('/admin/companies')->assertForbidden();
    }

    public function test_guest_is_redirected_from_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $this->get('/admin/companies')->assertRedirect('/login');
    }

    public function test_technician_cannot_use_admin_web_routes(): void
    {
        $a = $this->makeTenant();
        $slug = $a['company']->slug;

        $this->actingAs($a['technician'])->get("/{$slug}/clients")->assertNotFound();
        $this->actingAs($a['technician'])->get("/{$slug}/users/{$a['admin']->id}/template")->assertNotFound();
        $this->actingAs($a['technician'])->get("/{$slug}/whatsapp/connect")->assertNotFound();
    }

    public function test_company_admin_can_access_panel_and_own_resources(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['admin'])->get('/admin')->assertRedirect('/admin/company-settings');
        $this->actingAs($a['admin'])->get('/admin/buildings')->assertOk();
        $this->actingAs($a['admin'])->get('/admin/users')->assertOk();
        $this->actingAs($a['admin'])->get('/admin/company-settings')->assertOk();
    }

    public function test_company_admin_cannot_access_super_admin_resources(): void
    {
        $a = $this->makeTenant();

        $this->actingAs($a['admin'])->get('/admin/companies')->assertNotFound();
        $this->actingAs($a['admin'])->get('/admin/companies/create')->assertNotFound();
        $this->actingAs($a['admin'])->get("/admin/companies/{$a['company']->id}/edit")->assertNotFound();
        $this->actingAs($a['admin'])->get(CompanyOverview::getUrl(['company' => $a['company']->id]))->assertForbidden();
    }

    public function test_super_admin_can_manage_companies(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get('/admin/companies')->assertOk();
        $this->actingAs($superAdmin)->get("/admin/companies/{$a['company']->id}/edit")->assertOk();
        $this->actingAs($superAdmin)->get(CompanyOverview::getUrl(['company' => $a['company']->id]))->assertOk();

        $this->actingInPanel($superAdmin);

        Livewire::test(ListCompanies::class)
            ->assertCanSeeTableRecords([$a['company'], $b['company']]);
    }

    public function test_super_admin_only_sees_data_of_the_company_they_entered(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingInPanel($superAdmin);

        // Sin empresa elegida: no ve datos operativos de nadie.
        Livewire::test(ListBuildings::class)
            ->assertCanNotSeeTableRecords([$a['building'], $b['building']]);

        Livewire::test(ListCompanies::class)
            ->callTableAction('entrar', $a['company']);

        $this->assertSame($a['company']->id, session('selected_company_id'));

        Livewire::test(ListBuildings::class)
            ->assertCanSeeTableRecords([$a['building']])
            ->assertCanNotSeeTableRecords([$b['building']]);
    }

    public function test_super_admin_has_no_access_to_company_web_app(): void
    {
        $a = $this->makeTenant();
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->get("/{$a['company']->slug}/dashboard")
            ->assertForbidden();
    }

    public function test_company_admin_cannot_mount_super_admin_components(): void
    {
        $a = $this->makeTenant();

        $this->actingInPanel($a['admin']);

        // Las acciones de cobro manual (activarPago, pausar, etc.) viven en
        // CompanyResource: para un admin de empresa el componente ni monta.
        Livewire::test(ListCompanies::class)->assertNotFound();
    }

    public function test_company_settings_only_updates_allowed_fields(): void
    {
        $a = $this->makeTenant();
        $company = $a['company'];
        $company->forceFill([
            'whatsapp_connected' => true,
            'whatsapp_access_token' => 'TOKEN-SECRETO-DE-META',
            'whatsapp_phone_number_id' => '123',
        ])->save();
        $originalSlug = $company->slug;
        $originalTrial = $company->trial_ends_at->toDateTimeString();

        $this->actingInPanel($a['admin']);

        $component = Livewire::test(CompanySettings::class)
            ->assertDontSee('TOKEN-SECRETO-DE-META');

        $this->assertArrayNotHasKey('whatsapp_access_token', $component->get('data'));

        $component
            ->set('data.name', 'Nombre Nuevo')
            ->set('data.slug', 'slug-robado')
            ->set('data.trial_ends_at', now()->addYears(10)->toDateTimeString())
            ->set('data.is_active', false)
            ->call('save')
            ->assertHasNoFormErrors();

        $company->refresh();

        $this->assertSame('Nombre Nuevo', $company->name);
        $this->assertSame($originalSlug, $company->slug);
        $this->assertSame($originalTrial, $company->trial_ends_at->toDateTimeString());
        $this->assertTrue($company->is_active);
        $this->assertTrue($company->whatsapp_connected, 'Guardar la configuración no debe desconectar WhatsApp.');
        $this->assertSame('TOKEN-SECRETO-DE-META', $company->whatsapp_access_token);
    }

    public function test_admin_with_expired_access_is_sent_to_subscription_page(): void
    {
        $a = $this->makeTenant();
        $a['company']->update(['trial_ends_at' => now()->subDay()]);

        $this->actingAs($a['admin'])->get('/admin/buildings')->assertRedirect('/admin/subscription');

        Subscription::create([
            'company_id' => $a['company']->id,
            'provider' => 'manual',
            'status' => 'active',
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($a['admin']->fresh())->get('/admin/buildings')->assertOk();
    }

    public function test_paused_subscription_blocks_admin_even_during_trial(): void
    {
        $a = $this->makeTenant();

        Subscription::create([
            'company_id' => $a['company']->id,
            'provider' => 'manual',
            'status' => 'paused',
            'current_period_end' => now()->addMonth(),
        ]);

        $this->actingAs($a['admin'])->get('/admin/buildings')->assertRedirect('/admin/subscription');
    }
}
