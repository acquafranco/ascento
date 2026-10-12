<?php

namespace Tests\Feature\Subscriptions;

use App\Enums\PlanFeature;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\PhotoSection;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\VisitPhoto;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\MakesSignatures;
use Tests\Concerns\PassesRegistrationChallenge;
use Tests\TestCase;

/**
 * La prueba gratis de 30 días es el plan Empresa completo (también fotos de
 * antes y después y reportes de inspección), sin suscripción ni cobro. Al
 * terminar rige la lógica de siempre: sin plan contratado no hay acceso
 * (nada se borra) y, contratado, valen las funciones de ese plan.
 */
class TrialEmpresaTest extends TestCase
{
    use MakesSignatures, PassesRegistrationChallenge, RefreshDatabase;

    private Company $company;

    private User $admin;

    private User $technician;

    private Building $building;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Http::fake();
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));

        $this->post('/register', [
            ...$this->registrationProof(),
            'company_name' => 'Ascensores Prueba', 'name' => 'Dueña', 'email' => 'duena@prueba.test',
            'password' => 'clave-segura-123', 'password_confirmation' => 'clave-segura-123', 'cuit' => '30-71234567-1',
            'phone' => '11 4567-8900', 'province' => 'Buenos Aires', 'locality' => 'Lomas de Zamora', 'terms' => '1',
        ])->assertSessionHasNoErrors();

        $this->admin = User::where('email', 'duena@prueba.test')->sole();
        $this->company = $this->admin->company;
        $this->admin->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($this->admin);
        $this->technician = User::factory()->technician()->create(['company_id' => $this->company->id]);
        $this->building = Building::factory()->create(['company_id' => $this->company->id, 'elevator_count' => 1]);
        $this->building->users()->attach($this->technician->id, ['type' => 'maintenance']);
        $this->building->users()->attach($this->technician->id, ['type' => 'inspection']);
        auth()->logout();
    }

    private function url(string $path): string
    {
        return "/{$this->company->slug}/{$path}";
    }

    private function company(): Company
    {
        return Company::find($this->company->id);
    }

    /** Durante la prueba: fotos de mantenimiento y un reporte de inspección con foto. */
    private function useThePhotoFeatures(): void
    {
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        foreach (Company::PHOTO_SETTINGS as $column) {
            Livewire::test(CompanySettings::class)->call('savePhotoSetting', $column, true)->assertNotified();
        }

        $tech = $this->technician;
        $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->building->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026])->assertRedirect();
        $visit = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'maintenance')->sole();
        $zone = PhotoSection::zonesFor($this->company->id, 'maintenance')->first();
        $this->actingAs($tech)->postJson($this->url('visit-photos'), ['visit_id' => $visit->id, 'photo_section_id' => $zone->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->actingAs($tech)->post($this->url('delivery-notes/store'), [
            'building_id' => $this->building->id, 'description' => 'Mantenimiento.', 'month' => 10, 'year' => 2026, 'elevator_quantity' => 1,
            'freight_elevator_quantity' => 0, 'assignment_type' => 'maintenance', 'performed' => '1', 'signature_name' => 'Técnico', 'signature' => $this->validSignature(),
        ])->assertSessionHasNoErrors();

        $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->building->id, 'assignment_type' => 'inspection', 'month' => 10, 'year' => 2026, 'then' => 'report'])->assertRedirect();
        $inspection = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'inspection')->sole();
        $this->actingAs($tech)->post($this->url("visits/{$inspection->id}/report"), ['observations' => 'Inspección en la prueba.', 'photos' => [UploadedFile::fake()->image('i.jpg')]])->assertSessionHasNoErrors();
    }

    public function test_the_trial_starts_with_the_full_empresa_plan_including_photos_and_without_any_charge(): void
    {
        $company = $this->company();
        $this->assertTrue($company->onTrial());
        $this->assertSame('empresa', $company->plan()->slug);
        foreach (PlanFeature::cases() as $feature) {
            $this->assertTrue($company->plan()->allows($feature), $feature->value);
        }
        $this->assertTrue($company->hasWorkPhotos());

        // Interfaz: «Tomar foto» en Visitas y «Crear reporte» en la inspección.
        $this->company->forceFill(['photos_maintenance' => true])->save();
        $this->actingAs($this->technician)->get($this->url('buildings'))->assertOk()
            ->assertSee('data-quick-photos-for="maintenance"', false)->assertSee('data-inspection-report-link', false);

        // Servidor: fotos, reporte, y el panel las muestra.
        $this->useThePhotoFeatures();
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame(1, Report::withoutGlobalScopes()->where('kind', Report::KIND_INSPECTION)->count());
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->actingAs($this->admin)->get($photo->url())->assertOk();
        $this->actingAs($this->admin);
        Filament::setCurrentPanel(Filament::getPanel('ascensores_app'));
        $this->get(MaintenanceResource::getUrl('view', ['record' => DeliveryNote::sole()]))->assertOk()->assertSee($photo->thumbUrl(), false);

        // Ninguna suscripción ni cobro: no se creó nada ni se habló con Mercado Pago.
        $this->assertSame(0, Subscription::count());
        Http::assertNothingSent();
    }

    public function test_when_the_30_days_end_without_a_plan_there_is_no_access_and_nothing_is_deleted(): void
    {
        $this->useThePhotoFeatures();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $report = Report::withoutGlobalScopes()->where('kind', Report::KIND_INSPECTION)->sole();
        $files = Storage::disk('local')->allFiles();

        $this->travelTo(Carbon::parse('2026-10-31 10:01'));   // venció
        $company = $this->company();
        $this->assertFalse($company->onTrial());
        $this->assertFalse($company->hasActiveAccess());
        $this->assertFalse($company->hasWorkPhotos());

        // Lógica de siempre al vencer: el técnico queda afuera; el admin va a contratar.
        $this->actingAs($this->technician)->get($photo->url())->assertForbidden();
        $this->actingAs($this->technician)->get($this->url("visits/{$report->building_visit_id}/report"))->assertForbidden();
        $this->actingAs($this->admin)->get($photo->url())->assertRedirect('/admin/subscription');
        $this->actingAs($this->admin)->get(route('reports.pdf', $report))->assertRedirect('/admin/subscription');

        // Nada se borró y no se activó ninguna suscripción paga.
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(0, Subscription::count());
        Http::assertNothingSent();
    }

    public function test_after_the_trial_the_contracted_plan_decides_and_changes_both_ways_keep_everything(): void
    {
        $this->useThePhotoFeatures();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $files = Storage::disk('local')->allFiles();
        $this->travelTo(Carbon::parse('2026-11-02 10:00'));

        $contract = function (string $slug) {
            Subscription::updateOrCreate(['company_id' => $this->company->id], [
                'provider' => 'manual', 'plan' => $slug, 'status' => Subscription::ACTIVE,
                'amount' => SubscriptionPlan::findBySlug($slug)->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
            ]);
            // Cada petición real carga el usuario de nuevo (con la suscripción vigente).
            auth()->forgetGuards();
        };

        // Contrata Profesional: acceso a todo lo demás, sin fotos (que se conservan).
        $contract('profesional');
        $this->assertTrue($this->company()->hasActiveAccess());
        $this->assertFalse($this->company()->hasWorkPhotos());
        $this->actingAs($this->admin->fresh())->get($photo->url())->assertNotFound();
        $this->actingAs($this->technician->fresh())->get($this->url('buildings'))->assertOk()->assertDontSee('data-quick-photos', false)->assertDontSee('data-inspection-report-link', false);

        // Profesional → Empresa: vuelve todo.
        $contract('empresa');
        $this->assertTrue($this->company()->hasWorkPhotos());
        $this->actingAs($this->admin->fresh())->get($photo->url())->assertOk();
        $this->actingAs($this->technician->fresh())->get($this->url('buildings'))->assertOk()->assertSee('data-quick-photos-for="maintenance"', false);

        // Empresa → Profesional: se restringe otra vez, sin borrar nada.
        $contract('profesional');
        $this->actingAs($this->admin->fresh())->get($photo->url())->assertNotFound();
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        $this->assertTrue((bool) $this->company()->photos_maintenance);
    }
}
