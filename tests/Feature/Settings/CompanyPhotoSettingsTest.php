<?php

namespace Tests\Feature\Settings;

use App\Filament\Pages\CompanySettings;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Interruptores de fotos de «Mi empresa»: apagados en una empresa nueva, cada
 * uno se activa por separado y lo que muestra la pantalla es lo que queda
 * guardado (guardar y recargar no cambia nada).
 */
class CompanyPhotoSettingsTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    public function test_the_photo_switches_start_off_and_save_exactly_what_the_screen_shows(): void
    {
        $a = $this->makeTenant();
        $this->actingInPanel($a['admin']);
        $stored = fn () => array_values(Company::find($a['company']->id)->only(['photos_maintenance', 'photos_inspection', 'photos_work_order']));

        $this->assertSame([false, false, false], $stored());
        Livewire::test(CompanySettings::class)
            ->assertSet('data.photos_maintenance', false)->assertSet('data.photos_inspection', false)->assertSet('data.photos_work_order', false)
            ->set('data.photos_maintenance', true)->set('data.photos_inspection', true)
            ->call('save')->assertHasNoErrors();
        $this->assertSame([true, true, false], $stored());

        // Recargar muestra lo guardado; guardar de nuevo sin tocar no cambia nada.
        Livewire::test(CompanySettings::class)
            ->assertSet('data.photos_maintenance', true)->assertSet('data.photos_inspection', true)->assertSet('data.photos_work_order', false)
            ->call('save')->assertHasNoErrors();
        $this->assertSame([true, true, false], $stored());

        // Tocar el interruptor ya lo guarda (sin «Guardar cambios»).
        Livewire::test(CompanySettings::class)->set('data.photos_inspection', false)->assertNotified('Fotos de inspecciones: desactivadas');
        $this->assertSame([true, false, false], $stored());
        Livewire::test(CompanySettings::class)->set('data.photos_inspection', true);
        $this->assertSame([true, true, false], $stored());

        // Solo esas tres columnas.
        Livewire::test(CompanySettings::class)->call('savePhotoSetting', 'name', true)->assertStatus(404);
        $this->assertSame($a['company']->name, Company::find($a['company']->id)->name);

        // Cada uno por separado.
        Livewire::test(CompanySettings::class)->set('data.photos_maintenance', false)->set('data.photos_work_order', true)->call('save')->assertHasNoErrors();
        $this->assertSame([false, true, true], $stored());
    }

    public function test_tampered_switch_payloads_never_store_anything_but_a_boolean_for_their_own_company(): void
    {
        $a = $this->makeTenant();
        $b = $this->makeTenant();
        $this->actingInPanel($a['admin']);

        // Llamada directa con un valor que no es booleano (petición manipulada): no se guarda.
        Livewire::test(CompanySettings::class)->call('savePhotoSetting', 'photos_maintenance', 'sí, y además <script>')
            ->assertNotified('No se guardó el cambio')->assertSet('data.photos_maintenance', false);
        $this->assertFalse(Company::find($a['company']->id)->photos_maintenance);
        $this->assertSame(0, (int) DB::table('companies')->where('id', $a['company']->id)->value('photos_maintenance'));
        // Por el formulario, el interruptor convierte cualquier valor a sí/no: en la base solo hay 0 o 1.
        Livewire::test(CompanySettings::class)->set('data.photos_inspection', 'texto')->call('save');
        $this->assertContains((int) DB::table('companies')->where('id', $a['company']->id)->value('photos_inspection'), [0, 1]);

        // Lo que se activa es SIEMPRE la empresa de quien lo toca: la otra no cambia.
        Livewire::test(CompanySettings::class)->call('savePhotoSetting', 'photos_work_order', true);
        $this->assertTrue(Company::find($a['company']->id)->photos_work_order);
        $this->assertFalse(Company::find($b['company']->id)->photos_work_order);

        // El SuperAdmin no los toca desde «Mi empresa» (no es su empresa).
        auth()->logout(); // un admin de empresa no puede crear un SuperAdmin (el modelo lo impide)
        $root = User::factory()->superAdmin()->create(['company_id' => null]);
        $this->actingInPanel($root);
        $this->assertFalse(CompanySettings::canAccess());
    }
}
