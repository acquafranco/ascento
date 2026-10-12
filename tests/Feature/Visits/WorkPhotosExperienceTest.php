<?php

namespace Tests\Feature\Visits;

use App\Filament\Pages\Agenda;
use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\PhotoSection;
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
    use InteractsWithTenants, RefreshDatabase;

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
        PhotoSection::forCompany($this->a['company']->id);

        return PhotoSection::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->orderBy('id')->firstOrFail();
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

    public function test_the_admin_finds_the_photos_inside_each_maintenance_inspection_and_work_order(): void
    {
        $this->enable(['maintenance', 'inspection', 'work_order']);

        // Mantenimiento: antes (visita en curso) y después; completa la misma visita.
        $maintenance = $this->openVisit('maintenance');
        $this->upload(['visit_id' => $maintenance->id], 'before')->assertCreated();
        $this->upload(['visit_id' => $maintenance->id], 'after')->assertCreated();
        $this->sign('maintenance')->assertSessionHasNoErrors();
        $maintenanceNote = DeliveryNote::where('assignment_type', 'maintenance')->sole();
        $this->assertSame($maintenance->id, $maintenanceNote->building_visit_id);

        // Inspección: una foto de antes.
        $inspection = $this->openVisit('inspection');
        $this->upload(['visit_id' => $inspection->id], 'before')->assertCreated();
        $this->sign('inspection')->assertSessionHasNoErrors();
        $inspectionNote = DeliveryNote::where('assignment_type', 'inspection')->sole();

        // Orden: una de después (sin remito todavía).
        $order = $this->workOrder();
        $this->upload(['work_order_id' => $order->id], 'after')->assertCreated();

        $of = fn (string $field, int $id) => VisitPhoto::withoutGlobalScopes()->where($field, $id)->get();
        $this->actingInPanel($this->a['admin']);

        $page = $this->get(DeliveryNoteResource::getUrl('view', ['record' => $maintenanceNote]))->assertOk()->assertSee('Fotos del trabajo')
            ->assertSee('data-work-photos-stage="before"', false)->assertSee('data-work-photos-stage="after"', false);
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $of('building_visit_id', $inspection->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));
        $of('work_order_id', $order->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));

        $page = $this->get(DeliveryNoteResource::getUrl('view', ['record' => $inspectionNote]))->assertOk()->assertSee('Fotos del trabajo');
        $of('building_visit_id', $inspection->id)->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));

        $page = $this->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertOk()->assertSee('Fotos del trabajo')
            ->assertSee('data-work-photos-stage="after"', false);
        $of('work_order_id', $order->id)->each(fn ($p) => $page->assertSee($p->thumbUrl(), false));
        $of('building_visit_id', $maintenance->id)->each(fn ($p) => $page->assertDontSee($p->thumbUrl(), false));

        // Un trabajo sin fotos lo dice.
        $empty = $this->workOrder();
        $this->actingInPanel($this->a['admin']);
        $this->get(WorkOrderResource::getUrl('edit', ['record' => $empty]))->assertOk()->assertSee('Esta orden no tiene fotos.');
    }

    public function test_work_order_photos_stay_with_its_remito_after_signing(): void
    {
        $this->enable(['work_order']);
        $order = $this->workOrder();
        $this->upload(['work_order_id' => $order->id], 'before')->assertCreated();
        $this->sign('work_order', ['work_order_id' => $order->id])->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $note = DeliveryNote::sole();
        $this->assertSame($note->id, $photo->delivery_note_id);

        // Se ve en el remito y en la orden, una sola vez cada uno.
        $this->actingInPanel($this->a['admin']);
        $remito = $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($remito, 'data-work-photo="'.$photo->id.'"'));
        $orderPage = $this->get(WorkOrderResource::getUrl('edit', ['record' => $order]))->assertOk()->getContent();
        $this->assertSame(1, substr_count($orderPage, 'data-work-photo="'.$photo->id.'"'));
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
                $this->assertSame($type === $enabled, str_contains(substr($list, $start, $end - $start), 'data-start-visit'), "{$enabled} / {$type}");
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

    public function test_the_remito_form_offers_the_job_photos_without_mixing_them_into_the_remito(): void
    {
        $this->enable(['maintenance']);
        $form = fn (string $type) => $this->actingAs($this->a['technician'])->get($this->url("delivery-notes/create/building/{$this->a['building']->id}?assignment_type={$type}&month=10&year=2026"))->assertOk();

        $form('maintenance')->assertSee('data-remito-photos', false)->assertDontSee('data-file aria-label', false);
        $form('inspection')->assertDontSee('data-remito-photos', false);
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
