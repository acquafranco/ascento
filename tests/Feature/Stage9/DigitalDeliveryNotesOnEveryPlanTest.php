<?php

namespace Tests\Feature\Stage9;

use App\Enums\PlanFeature;
use App\Filament\Pages\PlanFeatures;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Plans\PlanFeatureManager;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Regla comercial: los remitos digitales están en los TRES planes. Una
 * empresa con cualquier plan crea, completa y firma el remito con el flujo
 * normal del técnico, lo ve en el historial y lo comparte con el cliente
 * (PDF, enlace, WhatsApp o correo). Es parte de la base: el SuperAdmin no
 * puede quitarlo desde «Planes».
 */
class DigitalDeliveryNotesOnEveryPlanTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->update(['elevator_count' => 1]);
    }

    private function url(string $path): string
    {
        return "/{$this->a['company']->slug}/{$path}";
    }

    public function test_every_plan_creates_signs_shares_and_keeps_the_history_of_remitos(): void
    {
        foreach (['inicial', 'profesional', 'empresa'] as $i => $slug) {
            $this->giveCompanyPlan($this->a['company'], $slug);
            $this->assertTrue($this->a['company']->plan()->allows(PlanFeature::DigitalDeliveryNotes), $slug);

            // El técnico completa y firma el remito con el flujo normal.
            $this->flushSession();
            $this->actingAs($this->a['technician'])->post($this->url('delivery-notes/store'), [
                'building_id' => $this->a['building']->id, 'description' => "Mantenimiento {$slug}", 'month' => 10 - $i, 'year' => 2026,
                'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'assignment_type' => 'maintenance', 'performed' => '1',
                'signature_name' => 'Encargado del edificio', 'signature' => $this->validSignature(),
            ])->assertSessionHasNoErrors()->assertRedirect();

            $note = DeliveryNote::where('description', "Mantenimiento {$slug}")->sole();
            $this->assertNotNull($note->signature);
            $this->assertTrue((bool) $note->performed);
            $this->assertNotNull($note->public_token);

            // Lo ve y lo comparte (PDF, enlace, WhatsApp o correo).
            $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/{$note->number}"))
                ->assertOk()->assertSee('Compartir remito')->assertSee('Enviar por WhatsApp')->assertSee('Descargar PDF')
                ->assertDontSee('data-plan-locked="digital_delivery_notes"', false);

            // El dueño: listado del panel y PDF.
            $this->flushSession();
            $this->actingAs($this->a['admin']);
            Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
            $this->get('/admin/delivery-notes')->assertOk()->assertSee($note->number);
            $this->assertSame('application/pdf', $this->get($this->url("delivery-notes/{$note->number}/pdf"))->assertOk()->headers->get('Content-Type'));

            // El cliente: el enlace y su PDF.
            auth()->logout();
            $this->flushSession();
            $this->get(route('delivery-notes.public', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->assertOk()->assertSee('Encargado del edificio');
            $this->assertSame('application/pdf', $this->get(route('delivery-notes.public.pdf', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->assertOk()->headers->get('Content-Type'));
        }

        // Historial: los tres remitos y sus visitas, sin que el plan los oculte.
        $this->assertSame(3, DeliveryNote::count());
        $this->assertSame(3, BuildingVisit::where('building_id', $this->a['building']->id)->count());
    }

    public function test_the_super_admin_cannot_take_digital_delivery_notes_out_of_a_plan(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->flushSession();
        $this->actingAs($super);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));

        $this->assertContains(PlanFeature::DigitalDeliveryNotes, PlanFeature::structural());
        $this->assertNotContains(PlanFeature::DigitalDeliveryNotes, PlanFeature::toggleable());

        // Con el servicio: aunque se pida sin ella, se conserva en los tres planes.
        foreach (['inicial', 'profesional', 'empresa'] as $slug) {
            app(PlanFeatureManager::class)->update($super, SubscriptionPlan::query()->where('slug', $slug)->sole(), []);
            $this->assertTrue(SubscriptionPlan::query()->where('slug', $slug)->sole()->allows(PlanFeature::DigitalDeliveryNotes), $slug);
        }

        // En la pantalla no hay casilla para quitarla y forzar el estado no la quita.
        $this->get('/admin/planes')->assertOk()->assertDontSee('data-feature="inicial.digital_delivery_notes"', false)->assertSee('Remitos digitales');
        Livewire::test(PlanFeatures::class)->set('features.inicial.digital_delivery_notes', false)->callAction('save');
        $this->assertTrue(SubscriptionPlan::query()->where('slug', 'inicial')->sole()->allows(PlanFeature::DigitalDeliveryNotes));
    }
}
