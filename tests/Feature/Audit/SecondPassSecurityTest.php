<?php

namespace Tests\Feature\Audit;

use App\Exceptions\PlanLimitReachedException;
use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Models\Building;
use App\Models\Company;
use App\Models\Report;
use App\Models\Subscription;
use App\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Segunda pasada: suscripciones sobre las funciones nuevas (fotos, PDF,
 * materiales), downgrade con datos de más, visibilidad de edificios del
 * técnico y datos personales fuera de los logs.
 */
class SecondPassSecurityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private Report $report;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->report = Report::factory()->withPhoto()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
    }

    private function expireTrial(): void
    {
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        $this->freshUsers();
    }

    /** Como en una request real: el usuario y su empresa se leen de nuevo. */
    private function freshUsers(): void
    {
        $this->a['admin'] = $this->a['admin']->fresh();
        $this->a['technician'] = $this->a['technician']->fresh();
    }

    private function subscribe(string $plan, array $attributes = []): void
    {
        Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago', 'plan' => $plan, 'status' => Subscription::AUTHORIZED,
            'amount' => 1, 'current_period_end' => now()->addMonth(), ...$attributes,
        ]);
        $this->a['company']->forgetPlan();
        $this->freshUsers();
    }

    public function test_files_and_pdf_follow_the_subscription(): void
    {
        $urls = [route('reports.pdf', $this->report), route('reports.photo', $this->report), $this->report->photos()->first()->url()];

        // En la prueba gratis: sí.
        foreach ($urls as $url) {
            $this->actingAs($this->a['admin'])->get($url)->assertOk();
        }

        // Prueba vencida sin pagar: el admin va a suscripción, el técnico ve el aviso.
        $this->expireTrial();
        foreach ($urls as $url) {
            $this->actingAs($this->a['admin'])->get($url)->assertRedirect('/admin/subscription');
            $this->actingAs($this->a['technician'])->get($url)->assertForbidden();
        }

        // Suscripta: vuelve.
        $this->subscribe('profesional');
        $this->actingAs($this->a['technician'])->get($urls[0])->assertOk();

        // Cancelada y terminado el período pago: se corta otra vez.
        $this->subscribe('profesional', ['status' => Subscription::CANCELED, 'current_period_end' => now()->subDay()]);
        $this->actingAs($this->a['technician'])->get($urls[0])->assertForbidden();
    }

    public function test_an_expired_company_cannot_create_reports_or_sign_remitos(): void
    {
        $this->expireTrial();
        $slug = $this->a['company']->slug;

        $this->actingAs($this->a['technician'])->post("/{$slug}/reports", [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1',
            'description' => 'Con la prueba vencida', 'priority' => 'alta',
            'photos' => [UploadedFile::fake()->image('a.jpg')],
        ])->assertForbidden();

        $this->actingAs($this->a['technician'])->post("/{$slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id, 'description' => 'x', 'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0, 'assignment_type' => 'maintenance',
            'signature_name' => 'T', 'signature' => $this->validSignature(),
        ])->assertForbidden();

        $this->assertSame(1, Report::count());
    }

    public function test_downgrade_with_more_buildings_than_the_new_limit_keeps_everything_but_blocks_new_ones(): void
    {
        $this->subscribe('profesional');
        Building::factory()->count(24)->create(['company_id' => $this->a['company']->id]); // 25 con el del tenant

        $this->subscribe('inicial'); // límite 20
        $this->actingInPanel($this->a['admin']);

        // No se borra ni se oculta nada…
        $this->assertSame(25, Building::count());

        // …se puede editar lo existente…
        Livewire::test(EditBuilding::class, ['record' => $this->a['building']->getRouteKey()])
            ->fillForm(['name' => 'Renombrado'])->call('save')->assertHasNoFormErrors();
        $this->assertSame('Renombrado', $this->a['building']->fresh()->name);

        // …pero no agregar (ni por el panel ni por el modelo).
        Livewire::test(CreateBuilding::class)->assertRedirect();
        $this->expectException(PlanLimitReachedException::class);
        Building::create(['company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id, 'name' => 'Uno más', 'address' => 'x']);
    }

    public function test_technicians_see_company_buildings_read_only_and_never_another_company(): void
    {
        $b = $this->makeTenant();
        $slug = $this->a['company']->slug;
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Edificio de la empresa sin asignar']);

        // Ve todos los edificios de SU empresa (decisión de producto: urgencias)…
        $this->actingAs($this->a['technician'])->get("/{$slug}/buildings/all")->assertOk()
            ->assertSee($other->name)
            ->assertDontSee($b['building']->name);

        // …pero no hay forma de modificarlos.
        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            foreach (["/{$slug}/buildings/{$other->id}", "/{$slug}/buildings/all", "/{$slug}/clients/{$other->client_id}"] as $url) {
                $this->assertContains($this->actingAs($this->a['technician'])->{$verb}($url, ['name' => 'Hackeado'])->status(), [403, 404, 405], strtoupper($verb).' '.$url);
            }
        }
        $this->actingAs($this->a['technician'])->get('/admin/buildings/'.$other->id.'/edit')->assertRedirect();

        $this->assertSame('Edificio de la empresa sin asignar', $other->fresh()->name);
    }

    public function test_whatsapp_logs_never_contain_phones_or_message_text(): void
    {
        Log::spy();

        $company = Company::factory()->create(['whatsapp_access_token' => 'tok', 'whatsapp_phone_number_id' => '123']);
        Http::fake(['graph.facebook.com/*' => Http::response(['ok' => true])]);

        app(WhatsAppService::class)->send($company, '5491123456789', 'Mensaje privado del cliente');

        Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode($context), '5491123456789') || str_contains(json_encode($context), 'Mensaje privado'));
        $this->assertTrue(true);
    }
}
