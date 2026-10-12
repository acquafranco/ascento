<?php

namespace Tests\Feature\Plans;

use App\Enums\PlanFeature;
use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\PhotoSection;
use App\Models\Report;
use App\Models\SubscriptionPlan;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Funciones fotográficas de la Etapa 5 (fotos de antes y después de
 * mantenimientos y órdenes, reporte de inspección con fotos y su consulta en
 * el panel): exclusivas del plan Empresa, en la interfaz y en el servidor.
 * Inicial y Profesional conservan visitas, firmas y remitos. Cambiar de plan
 * nunca borra fotos ni reportes: quedan guardados, sin acceso.
 */
class WorkPhotosPlanTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->update(['elevator_count' => 1]);
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);
        $this->a['company']->forceFill(['photos_maintenance' => true, 'photos_inspection' => true, 'photos_work_order' => true])->save();
    }

    private function url(string $path): string
    {
        return "/{$this->a['company']->slug}/{$path}";
    }

    private function zone(string $type = 'maintenance'): PhotoSection
    {
        return PhotoSection::zonesFor($this->a['company']->id, $type)->firstWhere('code', 'pozo');
    }

    private function sign(string $type, array $overrides = [])
    {
        return $this->actingAs($this->a['technician'])->post($this->url('delivery-notes/store'), [
            'building_id' => $this->a['building']->id, 'description' => 'Trabajo.', 'month' => 10, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'assignment_type' => $type, 'performed' => '1',
            'signature_name' => 'Técnico', 'signature' => $this->validSignature(), ...$overrides,
        ]);
    }

    /** Con el plan Empresa: una visita de mantenimiento con fotos, un reporte de inspección con foto y una orden con foto. */
    private function workWithPhotos(): array
    {
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $tech = $this->a['technician'];
        $start = fn (string $type) => $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => $type, 'month' => 10, 'year' => 2026]);

        $start('maintenance')->assertRedirect();
        $maintenance = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'maintenance')->sole();
        $this->actingAs($tech)->postJson($this->url('visit-photos'), ['visit_id' => $maintenance->id, 'photo_section_id' => $this->zone()->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('a.jpg')])->assertCreated();
        $this->sign('maintenance')->assertSessionHasNoErrors();

        $start('inspection')->assertRedirect();
        $inspection = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'inspection')->sole();
        $this->actingAs($tech)->post($this->url("visits/{$inspection->id}/report"), ['observations' => 'Inspección completa.', 'photos' => [UploadedFile::fake()->image('i.jpg')]])->assertSessionHasNoErrors();
        $this->sign('inspection')->assertSessionHasNoErrors();

        $this->actingAs($this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'priority' => 'medium', 'status' => 'in_progress']);
        $order->users()->attach($tech->id);
        $this->actingAs($tech)->postJson($this->url('visit-photos'), ['work_order_id' => $order->id, 'photo_section_id' => $this->zone('work_order')->id, 'stage' => 'after', 'photo' => UploadedFile::fake()->image('o.jpg')])->assertCreated();

        return [
            'maintenanceNote' => DeliveryNote::where('assignment_type', 'maintenance')->sole(),
            'inspectionNote' => DeliveryNote::where('assignment_type', 'inspection')->sole(),
            'inspection' => $inspection,
            'report' => Report::withoutGlobalScopes()->where('kind', Report::KIND_INSPECTION)->sole(),
            'order' => $order,
        ];
    }

    public function test_only_the_empresa_plan_includes_the_photo_features(): void
    {
        $this->assertTrue(SubscriptionPlan::findBySlug('empresa')->allows(PlanFeature::WorkPhotos));
        foreach (['inicial', 'profesional', 'professional'] as $slug) {
            $this->assertFalse((bool) SubscriptionPlan::findBySlug($slug)?->allows(PlanFeature::WorkPhotos), $slug);
        }
        // La prueba gratis es Profesional: sin fotos.
        $this->assertFalse(Company::find($this->a['company']->id)->hasWorkPhotos());
        $this->assertSame('Empresa', SubscriptionPlan::cheapestWith(PlanFeature::WorkPhotos)->shortName());
    }

    public function test_without_empresa_the_technician_sees_no_photo_controls_and_the_server_rejects_every_operation(): void
    {
        foreach (['inicial', 'profesional'] as $slug) {
            $this->giveCompanyPlan($this->a['company'], $slug);
            $tech = $this->a['technician'];
            $company = Company::find($this->a['company']->id);
            $this->assertFalse($company->photosEnabledFor('maintenance'), $slug);

            // Interfaz: ni «Tomar foto» ni «Crear reporte» (en Visitas, Inicio ni el remito).
            $this->actingAs($tech)->get($this->url('buildings'))->assertOk()
                ->assertDontSee('data-quick-photos', false)->assertDontSee('data-inspection-report-link', false);
            $this->actingAs($tech)->get($this->url('dashboard'))->assertOk()->assertDontSee('data-home-inspection-report', false);
            $this->actingAs($tech)->get($this->url("delivery-notes/create/building/{$this->a['building']->id}?assignment_type=inspection&month=10&year=2026"))->assertOk()
                ->assertDontSee('data-remito-inspection-report', false);

            // Servidor: abrir la visita (fotos o reporte), por JSON o formulario.
            foreach (['maintenance', 'inspection'] as $type) {
                $this->actingAs($tech)->postJson($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => $type, 'month' => 10, 'year' => 2026])->assertForbidden();
                $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => $type, 'month' => 10, 'year' => 2026, 'then' => 'report'])->assertRedirect();
            }
            $this->assertSame(0, BuildingVisit::withoutGlobalScope('completed')->count(), $slug);
        }

        // Una visita y una orden existentes (de antes): cargar fotos o un reporte, tampoco.
        $this->sign('inspection')->assertSessionHasNoErrors();
        $inspection = BuildingVisit::where('assignment_type', 'inspection')->sole();
        $this->actingAs($this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'priority' => 'medium', 'status' => 'in_progress']);
        $order->users()->attach($this->a['technician']->id);
        $tech = $this->a['technician'];

        $this->actingAs($tech)->postJson($this->url('visit-photos'), ['work_order_id' => $order->id, 'photo_section_id' => $this->zone('work_order')->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg')])->assertForbidden();
        $this->actingAs($tech)->patchJson($this->url('visit-photos/section'), ['work_order_id' => $order->id, 'photo_section_id' => $this->zone('work_order')->id, 'notes' => 'x'])->assertForbidden();
        $this->actingAs($tech)->getJson($this->url("visits/{$inspection->id}/report"))->assertForbidden();
        $this->actingAs($tech)->postJson($this->url("visits/{$inspection->id}/report"), ['observations' => 'Intento sin plan.'])->assertForbidden();
        $this->actingAs($tech)->get($this->url("work-orders/{$order->id}"))->assertOk()->assertDontSee('data-file aria-label', false)->assertDontSee('data-quick-photos', false);

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame(0, Report::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_inicial_and_profesional_keep_visits_signatures_and_remitos(): void
    {
        foreach (['inicial', 'profesional'] as $i => $slug) {
            $this->giveCompanyPlan($this->a['company'], $slug);
            $this->sign('maintenance', ['month' => 10 - $i])->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->sign('inspection')->assertSessionHasNoErrors();

        $this->assertSame(3, DeliveryNote::count());
        $this->assertSame(3, BuildingVisit::count());
        $note = DeliveryNote::where('assignment_type', 'inspection')->sole();
        $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/{$note->number}"))->assertOk();
        $this->actingInPanel($this->a['admin']);
        $this->get(InspectionResource::getUrl('view', ['record' => $note]))->assertOk()->assertSee('data-plan-locked="work_photos"', false);
    }

    public function test_changing_plan_keeps_photos_and_reports_but_blocks_every_access_until_empresa_again(): void
    {
        $work = $this->workWithPhotos();
        $photos = VisitPhoto::withoutGlobalScopes()->orderBy('id')->get();
        $reportPhoto = $work['report']->photos()->first();
        $files = Storage::disk('local')->allFiles();
        $this->assertSame(2, $photos->count());

        // Pasa a Profesional.
        $this->giveCompanyPlan($this->a['company'], 'profesional');

        // Nada se borró: filas, archivos e interruptores.
        $this->assertSame(2, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
        $this->assertTrue((bool) Company::find($this->a['company']->id)->photos_maintenance);

        // Archivos privados: ni el admin ni el técnico.
        foreach ([$this->a['admin'], $this->a['technician']] as $user) {
            foreach ($photos as $photo) {
                $this->actingAs($user)->get($photo->url())->assertNotFound();
                $this->actingAs($user)->get($photo->downloadUrl())->assertNotFound();
            }
            $this->actingAs($user)->get($reportPhoto->url())->assertNotFound();
            $this->actingAs($user)->get(route('reports.pdf', $work['report']))->assertNotFound();
        }

        // Técnico: la visita sin fotos (con el aviso) y el reporte, bloqueado.
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$work['maintenanceNote']->building_visit_id}"))->assertOk()
            ->assertSee('data-plan-locked="work_photos"', false)->assertDontSee($photos[0]->thumbUrl(), false);
        $this->actingAs($this->a['technician'])->getJson($this->url("visits/{$work['inspection']->id}/report"))->assertForbidden();
        $this->actingAs($this->a['technician'])->get($this->url("reports/{$work['report']->id}"))->assertNotFound();

        // Panel: mantenimiento, inspección y orden muestran el aviso, no las fotos; Reportes no lista el de inspección.
        $this->actingInPanel($this->a['admin']);
        $this->get(MaintenanceResource::getUrl('view', ['record' => $work['maintenanceNote']]))->assertOk()
            ->assertSee('data-plan-locked="work_photos"', false)->assertDontSee($photos[0]->thumbUrl(), false);
        $this->get(InspectionResource::getUrl('view', ['record' => $work['inspectionNote']]))->assertOk()
            ->assertSee('data-plan-locked="work_photos"', false)->assertDontSee('Inspección completa.')->assertDontSee($reportPhoto->thumbUrl(), false);
        $this->get(WorkOrderResource::getUrl('edit', ['record' => $work['order']]))->assertOk()
            ->assertSee('data-plan-locked="work_photos"', false)->assertDontSee($photos[1]->thumbUrl(), false);
        $this->get(ReportResource::getUrl('view', ['record' => $work['report']]))->assertNotFound();
        $this->assertFalse(ReportResource::getEloquentQuery()->whereKey($work['report']->id)->exists());

        // «Mi empresa»: los interruptores se ven, no se pueden cambiar (ni manipulando la llamada) y no se pierden.
        Livewire::test(CompanySettings::class)
            ->assertFormFieldDisabled('photos_maintenance')
            ->call('savePhotoSetting', 'photos_maintenance', false)
            ->set('data.company_name', 'x')->call('save');
        $this->assertSame([true, true, true], array_values(array_map('boolval', Company::find($this->a['company']->id)->only(['photos_maintenance', 'photos_inspection', 'photos_work_order']))));

        // Vuelve a Empresa: todo se ve de nuevo.
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->actingAs($this->a['admin'])->get($photos[0]->url())->assertOk();
        $this->actingAs($this->a['technician'])->get($reportPhoto->url())->assertOk();
        $this->actingInPanel($this->a['admin']);
        $this->get(MaintenanceResource::getUrl('view', ['record' => $work['maintenanceNote']]))->assertOk()->assertSee($photos[0]->thumbUrl(), false);
        $this->get(InspectionResource::getUrl('view', ['record' => $work['inspectionNote']]))->assertOk()->assertSee('Inspección completa.');
    }

    public function test_the_plan_of_another_company_never_opens_access_to_these_photos(): void
    {
        $work = $this->workWithPhotos();
        $photo = VisitPhoto::withoutGlobalScopes()->first();

        auth()->logout();
        $b = $this->makeTenant();
        $this->giveCompanyPlan($b['company'], 'empresa');

        // Otra empresa con plan Empresa: nada de A (archivo, PDF, reporte, panel).
        $this->actingAs($b['admin'])->get($photo->url())->assertNotFound();
        $this->actingAs($b['admin'])->get(route('reports.pdf', $work['report']))->assertNotFound();
        $this->actingAs($b['technician'])->get("/{$b['company']->slug}/visits/{$work['inspection']->id}/report")->assertNotFound();
        $this->actingInPanel($b['admin']);
        $this->get(MaintenanceResource::getUrl('view', ['record' => $work['maintenanceNote']]))->assertNotFound();
        $this->assertFalse(ReportResource::getEloquentQuery()->whereKey($work['report']->id)->exists());
    }

    public function test_with_empresa_the_inspector_reaches_the_report_from_home_and_from_the_remito(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $tech = $this->a['technician'];

        $this->actingAs($tech)->get($this->url('dashboard'))->assertOk()->assertSee('data-home-inspection-report', false)->assertSee('Crear reporte');
        $this->actingAs($tech)->get($this->url("delivery-notes/create/building/{$this->a['building']->id}?assignment_type=inspection&month=10&year=2026"))->assertOk()
            ->assertSee('data-remito-inspection-report', false)->assertSee('Crear reporte');

        // «Crear reporte» desde Inicio abre la inspección y lleva al reporte; después, Inicio lleva a esa misma.
        $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => 'inspection', 'month' => now()->month, 'year' => now()->year, 'then' => 'report'])
            ->assertRedirect();
        $visit = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'inspection')->sole();
        $this->actingAs($tech)->get($this->url('dashboard'))->assertOk()
            ->assertSee(route('inspection-reports.show', ['company' => $this->a['company']->slug, 'visit' => $visit->id]), false);
    }

    public function test_an_inspection_whose_report_is_in_the_trash_can_be_discarded(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $tech = $this->a['technician'];
        $this->actingAs($tech)->post($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => 'inspection', 'month' => 10, 'year' => 2026, 'then' => 'report']);
        $visit = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'inspection')->sole();
        $this->actingAs($tech)->post($this->url("visits/{$visit->id}/report"), ['observations' => 'Abierta por error.'])->assertSessionHasNoErrors();
        $report = Report::withoutGlobalScopes()->sole();

        // Con el reporte vigente, no.
        $this->actingAs($this->a['admin'])->delete($this->url("visits/{$visit->id}"))->assertSessionHasErrors('general');

        // La oficina lo manda a la papelera: ya no figura como hecho y la inspección se puede descartar.
        $report->delete();
        $this->actingAs($tech)->get($this->url('buildings'))->assertOk()->assertSee('data-inspection-report-link="create"', false);
        $this->actingAs($this->a['admin'])->delete($this->url("visits/{$visit->id}"))->assertSessionHasNoErrors();
        $this->assertFalse(BuildingVisit::withoutGlobalScope('completed')->whereKey($visit->id)->exists());

        // El reporte se conserva en la papelera, desvinculado de la visita que ya no existe.
        $kept = Report::withoutGlobalScopes()->withTrashed()->find($report->id);
        $this->assertNotNull($kept);
        $this->assertTrue($kept->trashed());
        $this->assertNull($kept->building_visit_id);
    }
}
