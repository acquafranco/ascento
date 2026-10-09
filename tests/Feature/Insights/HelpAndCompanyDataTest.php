<?php

namespace Tests\Feature\Insights;

use App\Filament\Pages\Agenda;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Clients\ClientResource;
use App\Livewire\HelpTip;
use App\Models\HelpDismissal;
use App\Models\User;
use App\Support\Help\HelpTopics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Ayudas contextuales con estado propio por ayuda y por usuario, y datos de
 * empresa opcionales en "Mi empresa".
 */
class HelpAndCompanyDataTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->a = $this->makeTenant();
        // Ya pasó la guía de bienvenida (si no, las ayudas esperan a que termine).
        $this->a['admin']->forceFill(['onboarding_completed_at' => now()])->save();
    }

    private const AGENDA_TEXT = 'Ascento arma solo la lista de mantenimientos';

    /** La tarjeta de ayuda (su botón). El texto solo no alcanza: el botón "?" lo repite. */
    private const TIP = 'wire:click="dismiss"';

    public function test_a_help_shows_until_dismissed_and_never_comes_back_by_itself(): void
    {
        $this->actingInPanel($this->a['admin']);
        $this->get(Agenda::getUrl())->assertOk()->assertSee(self::AGENDA_TEXT);

        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->assertSee('Entendido')->call('dismiss')->assertDontSee(self::AGENDA_TEXT);

        // Otra página, otro día, otra sesión: no vuelve.
        $this->get(Agenda::getUrl())->assertOk()->assertDontSee(self::AGENDA_TEXT);
        auth()->logout();
        $this->travel(3)->days();
        $this->actingInPanel($this->a['admin']->fresh())->get(Agenda::getUrl())->assertDontSee(self::AGENDA_TEXT);

        // Las demás ayudas siguen pendientes (estado propio de cada una).
        $this->get(ClientResource::getUrl())->assertSee(self::TIP, false);
        $this->assertSame(['agenda_intro'], HelpDismissal::where('user_id', $this->a['admin']->id)->pluck('key')->all());
    }

    public function test_one_help_can_be_reset_from_settings(): void
    {
        $admin = $this->a['admin'];
        $admin->dismissHelp('agenda_intro');
        $admin->dismissHelp('clients_intro');
        $this->actingInPanel($admin);

        Livewire::test(CompanySettings::class)
            ->assertSee('Volver a ver')
            ->call('resetHelp', 'agenda_intro');

        $this->assertFalse($admin->hasSeenHelp('agenda_intro'));
        $this->assertTrue($admin->hasSeenHelp('clients_intro')); // solo esa
        $this->get(Agenda::getUrl())->assertSee(self::AGENDA_TEXT);

        Livewire::test(CompanySettings::class)->call('resetAllHelp');
        $this->assertSame(0, $admin->helpDismissals()->count());

        Livewire::test(CompanySettings::class)->call('resetWelcomeGuide');
        $this->assertTrue($admin->fresh()->shouldAutoStartOnboarding());
    }

    public function test_unknown_keys_are_rejected_and_state_is_per_user(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CompanySettings::class)->call('resetHelp', 'cualquier-cosa')->assertStatus(404);
        Livewire::test(HelpTip::class, ['key' => 'inventada'])->call('dismiss');
        $this->assertSame(0, HelpDismissal::count());

        // Otro admin de la misma empresa sigue viendo sus ayudas.
        $this->a['admin']->dismissHelp('agenda_intro');
        $other = User::factory()->admin()->create(['company_id' => $this->a['company']->id, 'onboarding_completed_at' => now()]);
        $this->actingInPanel($other)->get(Agenda::getUrl())->assertSee(self::AGENDA_TEXT);
    }

    public function test_helps_wait_for_the_welcome_guide_and_never_show_to_technicians(): void
    {
        $this->a['admin']->forceFill(['onboarding_completed_at' => null, 'onboarding_skipped_at' => null])->save();
        $this->actingInPanel($this->a['admin']->fresh());
        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->assertDontSee(self::AGENDA_TEXT);

        $this->actingAs($this->a['technician']);
        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->assertDontSee(self::AGENDA_TEXT)->call('dismiss');
        $this->assertSame(0, HelpDismissal::count());
    }

    public function test_company_data_can_be_completed_later_and_is_validated(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CompanySettings::class)
            ->fillForm([
                'name' => 'Ascensores Alfa', 'business_name' => 'Alfa S.R.L.', 'cuit' => '30712345671',
                'province' => 'Córdoba', 'city' => 'Villa María', 'postal_code' => '5900',
                'tax_condition' => 'responsable_inscripto', 'activity' => 'Mantenimiento de ascensores',
                'gross_income_number' => '123-456789-0', 'activity_started_at' => '2015-03-01',
                'bank_name' => 'Banco Nación', 'bank_cbu' => '0110599520000001234567', 'bank_alias' => 'alfa.ascensores',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $company = $this->a['company']->fresh();
        $this->assertSame('30-71234567-1', $company->cuit);
        $this->assertSame('Córdoba', $company->province);
        $this->assertSame('Villa María', $company->city);
        $this->assertSame('responsable_inscripto', $company->tax_condition);
        $this->assertSame('2015-03-01', $company->activity_started_at->toDateString());
        $this->assertSame('0110599520000001234567', $company->bank_cbu);

        Livewire::test(CompanySettings::class)
            ->fillForm(['cuit' => '30-71234567-2', 'bank_cbu' => '123', 'bank_alias' => 'a b', 'province' => 'Narnia'])
            ->call('save')
            ->assertHasFormErrors(['cuit', 'bank_cbu', 'bank_alias', 'province']);

        // Lo opcional se puede dejar vacío (empresas viejas sin estos datos siguen guardando).
        Livewire::test(CompanySettings::class)
            ->fillForm(['cuit' => '', 'province' => null, 'city' => '', 'bank_cbu' => '', 'bank_alias' => ''])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_seeing_a_page_never_marks_its_help_as_seen(): void
    {
        $this->actingInPanel($this->a['admin']);

        foreach ([Agenda::getUrl(), ClientResource::getUrl(), Agenda::getUrl(), CompanySettings::getUrl()] as $url) {
            $this->get($url)->assertOk();
        }
        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->assertSee(self::AGENDA_TEXT);

        $this->assertSame(0, HelpDismissal::count()); // solo "Entendido" la marca
    }

    public function test_reset_all_really_shows_every_help_again(): void
    {
        foreach (array_keys(HelpTopics::all()) as $key) {
            $this->a['admin']->dismissHelp($key);
        }
        $this->actingInPanel($this->a['admin']);
        $this->get(Agenda::getUrl())->assertDontSee(self::AGENDA_TEXT);
        $this->get(ClientResource::getUrl())->assertDontSee(self::TIP, false);

        Livewire::test(CompanySettings::class)->call('resetAllHelp');

        $this->get(Agenda::getUrl())->assertSee(self::AGENDA_TEXT);
        $this->get(ClientResource::getUrl())->assertSee(self::TIP, false);
    }

    public function test_the_help_key_cannot_be_tampered_and_super_admins_store_nothing(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->set('key', 'clients_intro');
    }

    public function test_super_admin_does_not_see_or_store_company_helps(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAs($super)->withSession(['selected_company_id' => $this->a['company']->id]);

        Livewire::test(HelpTip::class, ['key' => 'agenda_intro'])->assertDontSee(self::AGENDA_TEXT)->call('dismiss');
        $this->assertSame(0, HelpDismissal::count());
        $this->get(CompanySettings::getUrl())->assertForbidden();
    }
}
