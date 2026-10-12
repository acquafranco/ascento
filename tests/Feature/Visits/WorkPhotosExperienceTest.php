<?php

namespace Tests\Feature\Visits;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\PhotoSection;
use App\Models\Report;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use App\Services\Insights\AttentionCenter;
use App\Services\Visits\VisitService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * «Fotos del trabajo» donde se usan: el admin las ve dentro de cada
 * mantenimiento, inspección y orden (no hay una galería global); el técnico
 * tiene «Tomar foto» y «Elegir de la galería» dentro de cada trabajo con las
 * fotos habilitadas, y la oficina ve las visitas en curso de más de 7 días.
 */
class WorkPhotosExperienceTest extends TestCase
{
    use InteractsWithTenants {
        makeTenant as makeTenantOnTrial;
    }
    use RefreshDatabase;

    /** Las funciones fotográficas son del plan Empresa: las empresas de estas pruebas lo tienen. */
    protected function makeTenant(): array
    {
        $tenant = $this->makeTenantOnTrial();
        $this->giveCompanyPlan($tenant['company'], 'empresa');

        return $tenant;
    }

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->update(['elevator_count' => 2]);
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);
    }

    private function url(string $path): string
    {
        return "/{$this->a['company']->slug}/{$path}";
    }

    private function enable(array $types): void
    {
        $this->a['company']->forceFill(collect(Company::PHOTO_SETTINGS)->mapWithKeys(fn ($column, $type) => [$column => in_array($type, $types, true)])->all())->save();
    }

    private function section(): PhotoSection
    {
        // Pozo: zona del sistema para mantenimientos y órdenes de trabajo.
        return PhotoSection::zonesFor($this->a['company']->id, 'maintenance')->firstWhere('code', 'pozo');
    }

    private function start(string $type)
    {
        return $this->actingAs($this->a['technician'])->post($this->url('visits/start'), [
            'building_id' => $this->a['building']->id, 'assignment_type' => $type, 'month' => 10, 'year' => 2026,
        ]);
    }

    private function upload(array $target, string $stage, ?string $key = null)
    {
        return $this->actingAs($this->a['technician'])->postJson($this->url('visit-photos'), [
            ...$target, 'photo_section_id' => $this->section()->id, 'stage' => $stage, 'client_key' => $key,
            'photo' => UploadedFile::fake()->image('foto.jpg', 640, 480),
        ]);
    }

    private function sign(string $type, array $overrides = [])
    {
        return $this->actingAs($this->a['technician'])->post($this->url('delivery-notes/store'), [
            'building_id' => $this->a['building']->id, 'description' => 'Trabajo.', 'month' => 10, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'assignment_type' => $type, 'performed' => '1',
            'signature_name' => 'Técnico', 'signature' => $this->validSignature(), ...$overrides,
        ]);
    }

    private function workOrder(): WorkOrder
    {
        $this->actingAs($this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'title' => 'Cambio de cable', 'type' => 'claim', 'priority' => 'medium', 'status' => 'in_progress']);
        $order->users()->attach($this->a['technician']->id);

        return $order;
    }

    private function openVisit(string $type): BuildingVisit
    {
        $this->start($type)->assertRedirect();

        return BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', $type)->where('in_progress', true)->sole();
    }

    /*
    |--------------------------------------------------------------------------
    | ADMINISTRADOR: DENTRO DE CADA TRABAJO, SIN GALERÍA GLOBAL
    |--------------------------------------------------------------------------
    */

    public function test_there_is_no_global_photo_gallery_in_the_admin(): void
    {
        $this->actingInPanel($this->a['admin']);

        // La navegación del panel (en cualquier página) no tiene la sección.
        $this->get(Agenda::getUrl())->assertOk()->assertSee('Mantenimientos')->assertDontSee('Fotos de trabajos');
        $this->get('/admin/fotos-de-trabajos')->assertNotFound();
        $this->assertFalse(class_exists('App\\Filament\\Resources\\VisitPhotos\\VisitPhotoResource'));
    }

    public function test_the_admin_finds_the_photos_inside_each_maintenance_and_work_order_and_the_inspection_report(): void
    {
        $this->enable(['maintenance', 'inspection', 'work_order']);

        // Mantenimiento: antes (visita en curso) y después; completa la misma visita.
        $maintenance = $this->openVisit('maintenance');
        $this->upload(['visit_id' => $maintenance->id], 'before')->assertCreated();
        $this->upload(['visit_id' => $maintenance->id], 'after')->assertCreated();
        $this->sign('maintenance')->assertSessionHasNoErrors();
        $maintenanceNote = DeliveryNote::where('assignment_type', 'maintenance')->sole();
        $this->assertSame($maintenance->id, $maintenanceNote->building_visit_id);

        // Inspección: sin fotos de antes y después; observaciones y una foto en su reporte.
        $inspection = $this->openVisit('inspection');
        $this->upload(['visit_id' => $inspection->id], 'before')->assertStatus(422)
            ->assertJsonValidationErrors(['photo' => 'Las fotos de la inspección se cargan en su reporte.']);
        $this->actingAs($this->a['technician'])->post($this->url("visits/{$inspection->id}/report"), [
            'observations' => 'Paracaídas probado. Cables con desgaste leve.',
            'photos' => [UploadedFile::fake()->image('cable.jpg', 640, 480)],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->sign('inspection')->assertSessionHasNoErrors();
        $inspectionNote = DeliveryNote::where('assignment_type', 'inspection')->sole();
        $report = Report::withoutGlobalScopes()->where('building_visit_id', $inspection->id)->sole();

        // Orden: una de después (sin remito todavía).
        $order = $this->workOrder();
        $this->upload(['work_order_id' => $order->id], 'after')->assertCreated();

        $of = fn (string $field, int $id) => VisitPhoto::withoutGlobalScopes()->where($field, $id)->get();
        $this->actingInPanel($this->a['admin']);

        $page = $this->get(MaintenanceResource::getUrl('view', ['record' => $maintenanceNote]))->assertOk()->assertSee('Fotos de antes y después')
            ->assertSee('data-work-photos-zone="pozo"', false)
            ->assertSee('data-work-photos-stage="before"', false)->assertSee('data-work-photos-stage="after"', false);
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $of('work_order_id', $order->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));

        $page = $this->get(InspectionResource::getUrl('view', ['record' => $inspectionNote]))->assertOk()
            ->assertSee('Reporte de inspección')->assertSee('Paracaídas probado')->assertSee('Ver reporte')
            ->assertSee($report->photos->first()->thumbUrl(), false);
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));
        $this->get(ReportResource::getUrl('view', ['record' => $report]))->assertOk()
            ->assertSee('Observaciones de la inspección')->assertDontSee('Prioridad');

        // Los remitos no muestran fotos.
        foreach ([$maintenanceNote, $inspectionNote] as $note) {
            $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertOk()
                ->assertDontSee('data-work-photos', false)->assertDontSee('data-inspection-report-panel', false);
        }

        $page = $this->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertOk()->assertSee('Fotos del trabajo')
            ->assertSee('data-work-photos-stage="after"', false);
        $of('work_order_id', $order->id)->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));

        // Un trabajo sin fotos lo dice.
        $empty = $this->workOrder();
        $this->actingInPanel($this->a['admin']);
        $this->get(WorkOrderResource::getUrl('edit', ['record' => $empty]))->assertOk()->assertSee('Esta orden no tiene fotos.');
    }

    public function test_work_order_photos_stay_with_the_order_and_never_show_in_its_remito(): void
    {
        $this->enable(['work_order']);
        $order = $this->workOrder();
        $this->upload(['work_order_id' => $order->id], 'before')->assertCreated();
        $this->sign('work_order', ['work_order_id' => $order->id])->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $note = DeliveryNote::sole();
        $this->assertSame($note->id, $photo->delivery_note_id);

        // Se ve una sola vez en la orden; en el remito, nunca (pantalla, detalle ni PDF).
        $this->actingInPanel($this->a['admin']);
        $remito = $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertOk()->getContent();
        $this->assertSame(0, substr_count($remito, 'data-work-photo="'.$photo->id.'"'));
        $orderPage = $this->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($orderPage, 'data-work-photo="'.$photo->id.'"'));
        $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/{$note->number}"))->assertOk()
            ->assertDontSee($photo->thumbUrl(), false)->assertDontSee('visit-photos', false);
    }

    /*
    |--------------------------------------------------------------------------
    | TÉCNICO: «TOMAR FOTO» Y «ELEGIR DE LA GALERÍA» DENTRO DE CADA TRABAJO
    |--------------------------------------------------------------------------
    */

    public function test_the_technician_sees_the_capture_buttons_only_for_enabled_job_types(): void
    {
        $order = $this->workOrder();
        $buttons = ['data-file aria-label="Tomar foto"', 'capture="environment"', 'data-file aria-label="Elegir de la galería"'];

        foreach (['maintenance', 'inspection', 'work_order'] as $enabled) {
            $this->enable([$enabled]);

            foreach (['maintenance', 'inspection'] as $type) {
                // Desde Visitas: el botón «Fotos del trabajo» solo para el tipo habilitado.
                $list = $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()->getContent();
                $start = strpos($list, 'data-visit-row="'.$type.'"');
                $end = strpos($list, 'data-visit-row=', $start + 10) ?: strpos($list, '</article>', $start);
                $row = substr($list, $start, $end - $start);
                // Fotos de antes y después: solo mantenimiento (habilitado). La inspección, siempre su reporte.
                $this->assertSame($type === 'maintenance' && $enabled === 'maintenance', str_contains($row, 'data-quick-photos-for='), "{$enabled} / {$type}");
                $this->assertSame($type === 'inspection', str_contains($row, 'data-inspection-report-link='), "{$enabled} / {$type}");
            }

            $orderPage = $this->actingAs($this->a['technician'])->get($this->url("work-orders/{$order->id}"))->assertOk();
            foreach ($buttons as $button) {
                $enabled === 'work_order' ? $orderPage->assertSee($button, false) : $orderPage->assertDontSee($button, false);
            }
            $enabled === 'work_order' ? $orderPage->assertSee('Antes')->assertSee('Después') : $orderPage->assertDontSee('data-photo-composer', false);
        }

        // Dentro de la visita: Antes/Después, la parte y los dos botones.
        $this->enable(['maintenance']);
        $visit = $this->openVisit('maintenance');
        $page = $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}"))->assertOk()
            ->assertSee('Fotos del trabajo')->assertSee('data-stage-choice', false)->assertSee('data-composer-section', false);
        foreach ($buttons as $button) {
            $page->assertSee($button, false);
        }

        // Apagado: la visita se sigue abriendo, sin botones (y el servidor rechaza la carga).
        $this->enable([]);
        $page = $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}"))->assertOk()->assertSee('data-photos-disabled', false);
        foreach ($buttons as $button) {
            $page->assertDontSee($button, false);
        }
        $this->upload(['visit_id' => $visit->id], 'before')->assertStatus(422);
        $this->upload(['work_order_id' => $order->id], 'before')->assertStatus(422);
    }

    public function test_the_technician_takes_photos_from_the_building_card_and_they_reach_the_admin_detail(): void
    {
        $this->enable(['maintenance']);

        // En Visitas, dentro de la tarjeta del edificio: Antes/Después, Tomar foto (cámara) y Galería.
        $list = $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()
            ->assertSee('data-quick-photos-for="maintenance"', false)->assertDontSee('data-quick-photos-for="inspection"', false)
            ->assertSee('data-quick-stage', false)->assertSee('capture="environment"', false)
            ->assertSee('aria-label="Tomar foto"', false)->assertSee('aria-label="Elegir de la galería"', false);

        // La primera foto abre la visita en curso sin cambiar de pantalla (siempre la misma).
        $start = ['building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026];
        $first = $this->actingAs($this->a['technician'])->postJson($this->url('visits/start'), $start)->assertOk()->json();
        $again = $this->actingAs($this->a['technician'])->postJson($this->url('visits/start'), $start)->assertOk()->json();
        $this->assertSame($first['visit_id'], $again['visit_id']);
        $this->assertStringEndsWith('/visits/'.$first['visit_id'], $first['url']);
        $this->upload(['visit_id' => $first['visit_id']], 'before')->assertCreated();
        $this->upload(['visit_id' => $first['visit_id']], 'after')->assertCreated();

        // Con la visita abierta, la tarjeta la usa (y cuenta sus fotos).
        $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()
            ->assertSee('data-visit-id="'.$first['visit_id'].'"', false)->assertSee('Ver todas (<span data-quick-count>2</span>)', false);

        // El remito completa esa misma visita y el admin las ve en el mantenimiento.
        $this->sign('maintenance')->assertSessionHasNoErrors();
        $note = DeliveryNote::sole();
        $this->assertSame($first['visit_id'], $note->building_visit_id);
        $this->assertSame(1, BuildingVisit::count());
        $this->actingInPanel($this->a['admin']);
        $page = $this->get(MaintenanceResource::getUrl('view', ['record' => $note]))->assertOk();
        VisitPhoto::withoutGlobalScopes()->get()->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $this->assertSame(2, substr_count($page->getContent(), 'data-work-photo="'));

        // Apagado: la apertura por JSON también se rechaza.
        $this->enable([]);
        $this->actingAs($this->a['technician'])->postJson($this->url('visits/start'), [...$start, 'month' => 11])->assertStatus(422);
    }

    public function test_the_work_order_card_in_the_list_has_the_capture_buttons_only_when_enabled_and_assigned(): void
    {
        $order = $this->workOrder();
        $other = WorkOrder::create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'priority' => 'medium', 'status' => 'in_progress']);
        $coworker = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $other->users()->attach($coworker->id);
        $list = fn () => $this->actingAs($this->a['technician'])->get($this->url('work-orders?status=in_progress'))->assertOk();

        $list()->assertDontSee('data-quick-photos-for=', false);
        $this->enable(['work_order']);
        $html = $list()->getContent();
        $this->assertSame(1, substr_count($html, 'data-quick-photos-for="work_order"'));
        $this->assertStringContainsString('data-work-order-id="'.$order->id.'"', $html);
        $this->assertStringNotContainsString('data-work-order-id="'.$other->id.'"', $html);
        $this->assertStringContainsString('aria-label="Tomar foto"', $html);
    }

    public function test_the_remito_screen_never_shows_photos(): void
    {
        $this->enable(['maintenance', 'inspection', 'work_order']);
        $visit = $this->openVisit('maintenance');
        $this->upload(['visit_id' => $visit->id], 'before')->assertCreated();
        $form = fn (string $type) => $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/create/building/{$this->a['building']->id}?assignment_type={$type}&month=10&year=2026"))->assertOk();

        // Ni botones de carga ni miniaturas ni cantidad de fotos: se cargan y se ven en su trabajo.
        foreach (['maintenance', 'inspection'] as $type) {
            $form($type)->assertDontSee('data-remito-photos', false)->assertDontSee('aria-label="Tomar foto"', false)
                ->assertDontSee('data-quick-photos', false)->assertDontSee('visit-photos', false);
        }
        $order = $this->workOrder();
        $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/create/work-order/{$order->id}"))->assertOk()
            ->assertDontSee('data-remito-photos', false)->assertDontSee('aria-label="Tomar foto"', false);
    }

    public function test_retrying_an_upload_never_duplicates_the_photo_or_the_visit(): void
    {
        $this->enable(['maintenance']);
        $visit = $this->openVisit('maintenance');
        $this->start('maintenance')->assertRedirect();

        $this->upload(['visit_id' => $visit->id], 'before', 'clave-1')->assertCreated();
        $this->upload(['visit_id' => $visit->id], 'before', 'clave-1')->assertOk();

        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame(1, BuildingVisit::withoutGlobalScope('completed')->count());
        $this->assertSame(1, count(Storage::disk('local')->allFiles('visits/'.$this->a['company']->id)) / 2);
    }

    public function test_upload_requests_for_another_company_or_unassigned_jobs_are_rejected(): void
    {
        $this->enable(['maintenance', 'work_order']);
        $visit = $this->openVisit('maintenance');
        $this->upload(['visit_id' => $visit->id], 'before')->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        auth()->logout();
        $b = $this->makeTenant();
        $b['company']->forceFill(['photos_maintenance' => true])->save();

        // Técnico de otra empresa: con el id de la visita de A, en su empresa o en la de A.
        $this->actingAs($b['technician'])->postJson("/{$b['company']->slug}/visit-photos", [
            'visit_id' => $visit->id, 'photo_section_id' => $this->section()->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();
        $this->actingAs($b['technician'])->postJson($this->url('visit-photos'), [
            'visit_id' => $visit->id, 'photo_section_id' => $this->section()->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertForbidden(); // la URL de otra empresa: la corta el middleware de empresa
        $this->actingAs($b['technician'])->get($photo->url())->assertNotFound();
        $this->actingAs($b['admin'])->get($photo->downloadUrl())->assertNotFound();

        // Técnico de A sin relación con el edificio ni la orden.
        $this->actingAs($outsider)->postJson($this->url('visit-photos'), [
            'visit_id' => $visit->id, 'photo_section_id' => $this->section()->id, 'stage' => 'after', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();
        $this->actingAs($outsider)->get($this->url("visits/{$visit->id}"))->assertNotFound();

        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
    }

    /*
    |--------------------------------------------------------------------------
    | VISITAS EN CURSO DE MÁS DE 7 DÍAS
    |--------------------------------------------------------------------------
    */

    public function test_visits_in_progress_for_more_than_seven_days_are_flagged_and_never_closed(): void
    {
        $this->enable(['maintenance']);
        $visit = $this->openVisit('maintenance');
        $this->upload(['visit_id' => $visit->id], 'before')->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        // 6 días: todavía no.
        $this->travelTo(Carbon::parse('2026-10-20 09:00'));
        $this->actingInPanel($this->a['admin']);
        $this->assertCount(0, app(VisitService::class)->stale($this->a['company']->id));
        $this->get(Agenda::getUrl())->assertOk()->assertDontSee('data-stale-visits', false);

        // 8 días: alerta en la Agenda (edificio, tipo, inicio, estado y enlace) y en el Centro de atención.
        $this->travelTo(Carbon::parse('2026-10-22 09:00'));
        $this->get(Agenda::getUrl())->assertOk()
            ->assertSee('data-stale-visit="'.$visit->id.'"', false)
            ->assertSee($this->a['building']->name)->assertSee('Mantenimiento')->assertSee('14/10/2026')->assertSee('En curso · sin remito')
            ->assertSee(route('visits.show', ['company' => $this->a['company']->slug, 'visit' => $visit->id]), false);
        $item = app(AttentionCenter::class)->basic($this->a['company'])->firstWhere('title', 'Visitas en curso hace más de 7 días');
        $this->assertNotNull($item);
        $this->assertSame(1, $item['count']);
        $this->assertStringContainsString('#visitas-en-curso', $item['url']);

        // El admin la abre y decide. Nada se cerró ni se borró solo.
        $this->actingAs($this->a['admin'])->get($this->url("visits/{$visit->id}"))->assertOk()->assertSee('data-discard-visit', false);
        $this->assertTrue($visit->fresh()->in_progress);
        $this->assertSame(0, DeliveryNote::count());
        Storage::disk('local')->assertExists($photo->path);

        // Otra empresa no la ve.
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin']);
        $this->get(Agenda::getUrl())->assertOk()->assertDontSee('data-stale-visits', false);

        // Con el remito firmado, deja de figurar.
        $this->travelTo(Carbon::parse('2026-10-23 09:00'));
        $this->sign('maintenance')->assertSessionHasNoErrors();
        $this->assertCount(0, app(VisitService::class)->stale($this->a['company']->id));
    }
}
