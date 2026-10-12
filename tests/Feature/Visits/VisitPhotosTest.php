<?php

namespace Tests\Feature\Visits;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\PhotoSection;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Models\WorkOrder;
use App\Services\Visits\VisitPhotoService;
use App\Services\Visits\VisitSchedule;
use App\Support\PhotoZones;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Fotos de antes y después gestionadas desde Visitas: la visita en curso
 * existe antes del remito, las fotos se guardan al instante y el remito
 * completa esa misma visita. Se habilitan por empresa y tipo de trabajo
 * (apagadas por defecto), el servidor lo verifica todo, el admin ve todas
 * las de su empresa y nunca llegan al portal, al enlace público ni al PDF.
 */
class VisitPhotosTest extends TestCase
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
        $this->enable('maintenance');
    }

    private function enable(string $type, bool $on = true): void
    {
        $this->a['company']->forceFill([Company::PHOTO_SETTINGS[$type] => $on])->save();
    }

    /** Zona del sistema (Pozo, Techo de cabina, Sala de máquinas…) de una empresa. */
    private function section(string $name = 'Pozo', ?int $companyId = null): PhotoSection
    {
        $code = PhotoZones::codeForLegacyName($name);

        return PhotoSection::zonesFor($companyId ?? $this->a['company']->id, 'maintenance')->firstWhere('code', $code);
    }

    private function start(?User $as = null, string $type = 'maintenance', ?Building $building = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])->post("/{$this->a['company']->slug}/visits/start", [
            'building_id' => ($building ?? $this->a['building'])->id, 'assignment_type' => $type, 'month' => 10, 'year' => 2026,
        ]);
    }

    private function openVisit(): BuildingVisit
    {
        $this->start()->assertRedirect();

        return BuildingVisit::withoutGlobalScope('completed')->where('in_progress', true)->latest('id')->firstOrFail();
    }

    private function upload(array $target, array $overrides = [], ?User $as = null, ?UploadedFile $file = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])->postJson("/{$this->a['company']->slug}/visit-photos", [
            ...$target,
            'photo_section_id' => $this->section()->id,
            'stage' => 'before',
            'photo' => $file ?? UploadedFile::fake()->image('pozo.jpg', 640, 480),
            ...$overrides,
        ]);
    }

    private function sign(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id,
            'description' => 'Mantenimiento con fotos.',
            'month' => 10, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance',
            'performed' => '1',
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
            ...$overrides,
        ]);
    }

    private function eval(): array
    {
        return VisitSchedule::load([$this->a['building']->id], 10, 2026)->evaluate($this->a['building']->id, 'maintenance', 10, 2026);
    }

    public function test_photos_are_off_by_default_and_the_server_blocks_uploads_for_disabled_types(): void
    {
        auth()->logout();
        $b = $this->makeTenant();
        $fresh = Company::find($b['company']->id);
        $this->assertSame([false, false, false], [$fresh->photos_maintenance, $fresh->photos_inspection, $fresh->photos_work_order]);

        // En A solo está habilitado mantenimiento: la inspección no tiene fotos de
        // antes y después (sus observaciones y fotos van en su reporte).
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);
        $page = $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/buildings")->assertOk();
        $this->assertSame(1, substr_count($page->getContent(), 'data-quick-photos-for='));
        $this->assertStringContainsString('data-inspection-report-link="start"', $page->getContent());

        // Una visita existente de inspección: el servidor rechaza la carga aunque se fuerce.
        $this->sign(['assignment_type' => 'inspection'])->assertSessionHasNoErrors();
        $inspection = BuildingVisit::where('assignment_type', 'inspection')->sole();
        $this->upload(['visit_id' => $inspection->id])->assertStatus(422)
            ->assertJsonValidationErrors(['photo' => 'La carga de fotos está desactivada para este tipo de trabajo.']);
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/visits/{$inspection->id}")->assertOk()
            ->assertSee('data-inspection-report-card', false)->assertDontSee('data-file aria-label', false);

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_visit_in_progress_gets_photos_and_the_remito_completes_that_same_visit(): void
    {
        $visit = $this->openVisit();
        $this->assertSame('maintenance:2026-10:1', $visit->period_key);

        // Abrirla de nuevo (doble toque, reintento, otro técnico) devuelve la misma.
        $this->start()->assertRedirect("/{$this->a['company']->slug}/visits/{$visit->id}");
        $this->assertSame(1, BuildingVisit::withoutGlobalScope('completed')->count());

        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $this->upload(['visit_id' => $visit->id], ['stage' => 'after', 'notes' => 'Pozo limpio'])->assertCreated();

        // En curso: no cuenta como realizada ni aparece en el historial.
        $this->assertSame([0, 1], [$this->eval()['done'], $this->eval()['pending']]);
        $this->assertSame(0, BuildingVisit::count());
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/buildings")->assertSee('En curso · sin remito')->assertSee('Hacer remito');
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/create/building/{$this->a['building']->id}?assignment_type=maintenance&month=10&year=2026")
            ->assertOk()->assertSee('data-open-visit', false)
            // El remito no muestra fotos (ni cuántas hay): se ven en su trabajo.
            ->assertDontSee('con 2 fotos')->assertDontSee('data-quick-photos', false)->assertDontSee('visit-photos', false);

        // El remito completa ESA visita: no se crea otra.
        $this->sign()->assertSessionHasNoErrors();
        $this->assertSame(1, BuildingVisit::withoutGlobalScope('completed')->count());
        $this->assertSame($visit->id, BuildingVisit::sole()->id);
        $this->assertFalse(BuildingVisit::sole()->in_progress);
        $note = DeliveryNote::sole();
        $this->assertSame($visit->id, $note->building_visit_id);
        $this->assertSame([$note->id, $note->id], VisitPhoto::withoutGlobalScopes()->pluck('delivery_note_id')->all());
        $this->assertSame([1, 0], [$this->eval()['done'], $this->eval()['pending']]);
    }

    public function test_photos_are_optional_and_can_be_added_after_the_remito(): void
    {
        $this->sign()->assertSessionHasNoErrors();
        $visit = BuildingVisit::sole();

        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/visits/{$visit->id}")->assertOk()->assertSee('data-file aria-label', false);
        $this->upload(['visit_id' => $visit->id], ['stage' => 'after'])->assertCreated();

        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->assertSame([$visit->id, DeliveryNote::sole()->id, $this->a['technician']->id], [$photo->building_visit_id, $photo->delivery_note_id, $photo->uploaded_by]);
        $this->assertSame(1, BuildingVisit::count());
    }

    public function test_limits_formats_and_fake_images_without_leaving_files(): void
    {
        $visit = $this->openVisit();
        $target = ['visit_id' => $visit->id];

        foreach (range(1, 3) as $i) {
            $this->upload($target)->assertCreated();
        }
        $this->upload($target)->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->upload($target, [], null, UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'))->assertStatus(422);
        $this->upload($target, [], null, UploadedFile::fake()->image('enorme.jpg')->size(VisitPhoto::MAX_KB + 1))->assertStatus(422);
        $this->upload($target, [], null, UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo "no soy una imagen";'))->assertStatus(422);
        $this->assertSame(3, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertCount(6, Storage::disk('local')->allFiles()); // 3 fotos + 3 miniaturas

        // 30 por visita y 60 MB por visita.
        $template = VisitPhoto::withoutGlobalScopes()->first()->getAttributes();
        DB::table('visit_photos')->insert(collect(range(4, VisitPhoto::MAX_PER_VISIT))->map(fn ($i) => [
            ...Arr::except($template, ['id']), 'photo_section_id' => $this->section('Techo')->id, 'stage' => $i % 2 ? 'before' : 'after',
            'path' => "visits/{$this->a['company']->id}/x{$i}.jpg", 'thumb_path' => null, 'client_key' => null, 'size' => 1000,
        ])->all());
        $other = ['photo_section_id' => $this->section('Sala de máquinas')->id];
        $this->upload($target, $other)->assertStatus(422)->assertJsonValidationErrors(['photo' => 'Podés cargar hasta 30 fotos por visita.']);

        VisitPhoto::withoutGlobalScopes()->where('size', 1000)->limit(5)->get()->each->delete();
        VisitPhoto::withoutGlobalScopes()->where('size', 1000)->limit(1)->update(['size' => VisitPhoto::MAX_TOTAL_BYTES]);
        $this->upload($target, $other)->assertStatus(422)->assertJsonValidationErrors(['photo' => 'Las fotos de esta visita superan el tamaño máximo permitido.']);
    }

    public function test_a_retried_upload_with_the_same_identity_is_not_duplicated(): void
    {
        $visit = $this->openVisit();
        $first = $this->upload(['visit_id' => $visit->id], ['client_key' => 'foto-1'])->assertCreated()->json('id');
        $again = $this->upload(['visit_id' => $visit->id], ['client_key' => 'foto-1'])->assertOk()->json('id');

        $this->assertSame($first, $again);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertCount(2, Storage::disk('local')->allFiles());
        $this->upload(['visit_id' => $visit->id], ['client_key' => '../../x'])->assertStatus(422);
    }

    public function test_visit_photos_lose_their_gps_metadata_and_huge_images_are_rejected(): void
    {
        $visit = $this->openVisit();
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $exif = "Exif\x00\x00MM\x00\x2A".'GPS-UBICACION-SECRETA';
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->upload(['visit_id' => $visit->id], [], null, UploadedFile::fake()->createWithContent('pozo.jpg', $jpeg))->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString('GPS-UBICACION-SECRETA', Storage::disk('local')->get($photo->path));
        $this->assertStringNotContainsString('GPS-UBICACION-SECRETA', Storage::disk('local')->get($photo->thumb_path));
        $this->assertStringStartsWith('visits/'.$this->a['company']->id.'/', $photo->path);

        config(['media.photo_max_pixels' => 100 * 100]);
        $this->upload(['visit_id' => $visit->id], ['stage' => 'after'], null, UploadedFile::fake()->image('enorme.png', 200, 200))->assertStatus(422);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_who_can_open_and_download_the_photos(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $url = "/files/visit-photos/{$photo->id}";

        // Admin: abre y descarga.
        $this->actingAs($this->a['admin'])->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->a['admin'])->get($url.'?download=1')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename=foto-'.$photo->id.'-pozo-antes.jpg');
        // El técnico de la visita y otro técnico ASIGNADO al edificio para ese trabajo.
        $this->actingAs($this->a['technician'])->get($url.'?thumb=1')->assertOk();
        $coworker = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($coworker->id, ['type' => 'maintenance']);
        $this->actingAs($coworker)->get($url)->assertOk();
        // Técnico sin relación con la visita, o solo inspector del edificio: no.
        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($outsider)->get($url)->assertNotFound();
        $this->actingAs($outsider)->get("/{$this->a['company']->slug}/visits/{$visit->id}")->assertNotFound();
        $inspector = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($inspector->id, ['type' => 'inspection']);
        $this->actingAs($inspector)->get($url)->assertNotFound();

        auth()->logout();
        $b = $this->makeTenant();
        $this->actingAs($b['admin'])->get($url)->assertNotFound();
        $this->actingAs($b['technician'])->get($url.'?download=1')->assertNotFound();
        $this->actingAs($b['technician'])->get("/{$b['company']->slug}/visits/{$visit->id}")->assertNotFound();

        $client = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $client->portalBuildings()->sync([$this->a['building']->id]);
        $this->actingAs($client)->get($url)->assertNotFound();

        auth()->logout();
        $super = User::factory()->superAdmin()->create();
        $this->assertFalse($photo->canBeViewedBy($super));
        session(['selected_company_id' => $this->a['company']->id]);
        $this->assertTrue($photo->canBeViewedBy($super));

        auth()->logout();
        session()->flush();
        $this->get($url)->assertRedirect();
        // Ninguna ruta pública sirve el archivo privado conociendo su nombre.
        $this->get('/storage/'.$photo->path)->assertNotFound();
        $this->get('/'.$photo->path)->assertNotFound();
    }

    public function test_the_admin_sees_every_photo_of_a_job_inside_it_and_nothing_from_another_company(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $coworker = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($coworker->id, ['type' => 'maintenance']);
        $this->upload(['visit_id' => $visit->id], ['stage' => 'after'], $coworker)->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $note = DeliveryNote::sole();
        [$before, $after] = VisitPhoto::withoutGlobalScopes()->orderBy('id')->get()->all();

        auth()->logout();
        $b = $this->makeTenant();
        $b['company']->forceFill(['photos_maintenance' => true])->save();
        $this->actingAs($b['technician'])->post("/{$b['company']->slug}/visits/start", ['building_id' => $b['building']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026]);
        $foreign = BuildingVisit::withoutGlobalScopes()->where('company_id', $b['company']->id)->sole();
        $this->actingAs($b['technician'])->postJson("/{$b['company']->slug}/visit-photos", [
            'visit_id' => $foreign->id, 'photo_section_id' => $this->section('Pozo', $b['company']->id)->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertCreated();
        $foreignPhoto = VisitPhoto::withoutGlobalScopes()->where('company_id', $b['company']->id)->sole();

        // Dentro del mantenimiento: por zona, Antes y Después, de las dos personas.
        $this->actingInPanel($this->a['admin']);
        $this->get(MaintenanceResource::getUrl('view', ['record' => $note]))->assertOk()
            ->assertSee('Fotos de antes y después')
            ->assertSee('data-work-photos-zone="pozo"', false)
            ->assertSee('data-work-photos-stage="before"', false)->assertSee('data-work-photos-stage="after"', false)
            ->assertSee($before->thumbUrl(), false)->assertSee($after->downloadUrl(), false)
            ->assertDontSee($foreignPhoto->thumbUrl(), false);
        $this->get($before->downloadUrl())->assertOk()->assertDownload();
        // El remito no muestra fotos.
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertOk()
            ->assertDontSee($before->thumbUrl(), false)->assertDontSee('data-work-photos', false);

        // Otra empresa: ni el mantenimiento, ni el remito, ni la foto.
        $this->actingInPanel($b['admin']);
        $this->get(MaintenanceResource::getUrl('view', ['record' => $note]))->assertNotFound();
        $this->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))->assertNotFound();
        $this->get($before->url())->assertNotFound();
    }

    public function test_manipulated_requests_never_cross_companies_buildings_or_types(): void
    {
        $visit = $this->openVisit();

        auth()->logout();
        $b = $this->makeTenant();
        $b['company']->forceFill(['photos_maintenance' => true, 'photos_work_order' => true])->save();
        $foreignOrder = new WorkOrder(['building_id' => $b['building']->id, 'title' => 'x', 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending']);
        $foreignOrder->company_id = $b['company']->id;
        $foreignOrder->saveQuietly();

        // A la visita de A desde B, o a una orden de B desde A.
        $this->actingAs($b['technician'])->postJson("/{$b['company']->slug}/visit-photos", [
            'visit_id' => $visit->id, 'photo_section_id' => $this->section('Pozo', $b['company']->id)->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();
        $this->upload(['work_order_id' => $foreignOrder->id])->assertNotFound();
        // Sección de otra empresa.
        $this->upload(['visit_id' => $visit->id], ['photo_section_id' => $this->section('Pozo', $b['company']->id)->id])->assertNotFound();
        // Técnico de A sin asignación a ese edificio (aunque conozca los ids).
        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->upload(['visit_id' => $visit->id], [], $outsider)->assertNotFound();
        $this->start($outsider)->assertForbidden();
        // Un equipo de otro edificio no se asocia.
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'elevator_count' => 1]);
        $foreignElevator = Elevator::withoutGlobalScopes()->where('building_id', $other->id)->first();
        $this->upload(['visit_id' => $visit->id], ['elevator_id' => $foreignElevator->id])->assertCreated();
        $this->assertNull(VisitPhoto::withoutGlobalScopes()->sole()->elevator_id);

        // Quitar la foto: solo quien la subió (ni el admin ni otra empresa).
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['admin'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$photo->id}")->assertNotFound();
        $this->actingAs($b['technician'])->deleteJson("/{$b['company']->slug}/visit-photos/{$photo->id}")->assertNotFound();
        Storage::disk('local')->assertExists($photo->path);

        $this->actingAs($this->a['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$photo->id}")->assertOk();
        Storage::disk('local')->assertMissing([$photo->path, $photo->thumb_path]);
    }

    public function test_disabling_photos_keeps_every_historical_photo_and_its_access(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        $this->enable('maintenance', false);

        $this->upload(['visit_id' => $visit->id], ['stage' => 'after'])->assertStatus(422);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        Storage::disk('local')->assertExists($photo->path);
        $this->actingAs($this->a['admin'])->get($photo->url())->assertOk();
        $this->actingAs($this->a['technician'])->get($photo->url())->assertOk();
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/visits/{$visit->id}")->assertOk()
            ->assertSee($photo->thumbUrl(), false)->assertDontSee('data-file aria-label', false);
        $this->assertSame($visit->id, DeliveryNote::sole()->building_visit_id);
        // Sin el botón para empezar visitas nuevas con fotos.
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/buildings?month=11&year=2026")->assertDontSee('data-quick-photos-for=', false);
    }

    public function test_photos_and_visits_in_progress_never_reach_the_portal_the_public_link_or_the_pdf(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $note = DeliveryNote::sole();
        $note->forceFill(['shared_with_client' => true])->save();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        // El remito (también el interno) no muestra fotos: se ven en su trabajo.
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")->assertOk()
            ->assertDontSee('data-visit-photos', false)->assertDontSee($photo->thumbUrl(), false);
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/visits/{$note->building_visit_id}")->assertOk()
            ->assertSee($photo->thumbUrl(), false);

        auth()->logout();
        $this->get(route('delivery-notes.public', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->assertOk()
            ->assertDontSee('visit-photos')->assertDontSee('data-visit-photos', false);
        $pdf = $this->get(route('delivery-notes.public.pdf', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->getContent();
        $this->assertStringNotContainsString('visit-photos', $pdf);

        // Una visita en curso (noviembre) no aparece en el portal del cliente.
        $this->travelTo(Carbon::parse('2026-11-05 09:00'));
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/visits/start", ['building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => 11, 'year' => 2026]);
        $this->assertSame(1, BuildingVisit::withoutGlobalScope('completed')->where('in_progress', true)->count());

        auth()->logout();
        $client = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $client->portalBuildings()->sync([$this->a['building']->id]);
        $this->actingAs($client)->get(route('portal.delivery-note', $note))->assertOk()->assertDontSee('visit-photos');
        $visits = $this->actingAs($client)->get(route('portal.visits', ['type' => 'maintenance']))->assertOk()->viewData('visits');
        $this->assertSame([$visit->id], collect($visits->items())->pluck('id')->all());
    }

    public function test_work_order_photos_before_the_remito_move_to_its_visit_and_remito(): void
    {
        $this->enable('work_order');
        $this->actingAs($this->a['admin']);
        $order = WorkOrder::create(['building_id' => $this->a['building']->id, 'title' => 'Cambio de cable', 'type' => 'claim', 'priority' => 'medium', 'status' => 'in_progress']);
        $order->users()->attach($this->a['technician']->id);

        // «Fotos del trabajo» dentro de la orden, con sus botones.
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/work-orders/{$order->id}")->assertOk()
            ->assertSee('data-work-order-photos', false)->assertSee('data-file aria-label="Tomar foto"', false)->assertSee('data-file aria-label="Elegir de la galería"', false);
        $this->upload(['work_order_id' => $order->id])->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->assertSame([$order->id, null], [$photo->work_order_id, $photo->building_visit_id]);

        // Otro técnico, no asignado a la orden: nada.
        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->upload(['work_order_id' => $order->id], [], $outsider)->assertNotFound();
        $this->actingAs($outsider)->get($photo->url())->assertNotFound();

        $this->sign(['work_order_id' => $order->id, 'assignment_type' => 'work_order'])->assertSessionHasNoErrors();
        $photo->refresh();
        $visit = BuildingVisit::where('work_order_id', $order->id)->sole();
        $this->assertSame([$visit->id, DeliveryNote::sole()->id], [$photo->building_visit_id, $photo->delivery_note_id]);

        // Apagado para órdenes: no se cargan nuevas; la histórica se sigue viendo.
        $this->enable('work_order', false);
        $this->upload(['work_order_id' => $order->id], ['stage' => 'after'])->assertStatus(422);
        $this->actingAs($this->a['admin'])->get($photo->url())->assertOk();
    }

    public function test_discarding_a_visit_in_progress_removes_it_with_its_photos(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['technician'])->delete("/{$this->a['company']->slug}/visits/{$visit->id}")->assertRedirect();

        $this->assertSame(0, BuildingVisit::withoutGlobalScope('completed')->count());
        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        Storage::disk('local')->assertMissing([$photo->path, $photo->thumb_path]);

        // Con remito no se puede descartar.
        $this->sign()->assertSessionHasNoErrors();
        $done = BuildingVisit::sole();
        $this->actingAs($this->a['technician'])->delete("/{$this->a['company']->slug}/visits/{$done->id}")->assertSessionHasErrors('general');
        $this->assertSame(1, BuildingVisit::count());
    }

    public function test_cleanup_only_removes_old_unlinked_photos_and_never_visit_or_order_photos(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $linked = VisitPhoto::withoutGlobalScopes()->sole();

        // Una foto de una orden (todavía sin visita) y una "en preparación" del flujo anterior.
        $order = WorkOrder::withoutGlobalScopes()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'title' => 'x', 'type' => 'claim', 'priority' => 'medium', 'status' => 'pending']);
        $base = Arr::except($linked->getAttributes(), ['id']);
        $orderPhoto = DB::table('visit_photos')->insertGetId([...$base, 'building_visit_id' => null, 'work_order_id' => $order->id, 'path' => "visits/{$this->a['company']->id}/orden.jpg", 'thumb_path' => null, 'client_key' => null]);
        Storage::disk('local')->put("visits/{$this->a['company']->id}/vieja.jpg", 'x');
        $legacy = DB::table('visit_photos')->insertGetId([...$base, 'building_visit_id' => null, 'work_order_id' => null, 'batch' => (string) Str::uuid(), 'path' => "visits/{$this->a['company']->id}/vieja.jpg", 'thumb_path' => null, 'client_key' => null]);

        $this->travel(25)->hours();
        $this->artisan('visit-photos:prune')->expectsOutputToContain('Fotos sin vincular borradas: 1')->assertSuccessful();

        $this->assertNull(VisitPhoto::withoutGlobalScopes()->find($legacy));
        Storage::disk('local')->assertMissing("visits/{$this->a['company']->id}/vieja.jpg");
        $this->assertNotNull(VisitPhoto::withoutGlobalScopes()->find($orderPhoto));
        $this->assertNotNull($linked->fresh());
        Storage::disk('local')->assertExists($linked->path);
        $this->assertSame(['deleted' => 0, 'failed' => 0], app(VisitPhotoService::class)->prune());
    }

    public function test_the_cleanup_runs_daily_from_the_laravel_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'visit-photos:prune'));

        $this->assertNotNull($event);
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_section_note_and_elevator_apply_to_its_photos(): void
    {
        $visit = $this->openVisit();
        $this->upload(['visit_id' => $visit->id])->assertCreated();
        $this->upload(['visit_id' => $visit->id], ['stage' => 'after'])->assertCreated();
        $elevator = Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->first();

        $this->actingAs($this->a['technician'])->patchJson("/{$this->a['company']->slug}/visit-photos/section", [
            'visit_id' => $visit->id, 'photo_section_id' => $this->section()->id, 'notes' => 'Guías lubricadas', 'elevator_id' => $elevator->id,
        ])->assertOk()->assertJson(['updated' => 2]);

        $this->assertSame([['Guías lubricadas', $elevator->id], ['Guías lubricadas', $elevator->id]], VisitPhoto::withoutGlobalScopes()->get()->map(fn ($p) => [$p->notes, $p->elevator_id])->all());

        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($outsider)->patchJson("/{$this->a['company']->slug}/visit-photos/section", ['visit_id' => $visit->id, 'photo_section_id' => $this->section()->id, 'notes' => 'x'])->assertNotFound();
    }

    public function test_photo_zones_are_defined_by_the_system_and_old_sections_keep_their_photos(): void
    {
        $companyId = $this->a['company']->id;

        // Zonas del sistema por tipo de trabajo (sin administración por empresa).
        $this->assertSame(['pozo', 'sala_maquinas', 'techo', 'cabina'], PhotoSection::zonesFor($companyId, 'maintenance')->pluck('code')->all());
        $this->assertSame(['trabajo', 'pozo', 'sala_maquinas', 'techo', 'cabina'], PhotoSection::zonesFor($companyId, 'work_order')->pluck('code')->all());
        $this->assertTrue(PhotoSection::zonesFor($companyId, 'inspection')->isEmpty());
        // Pedirlas otra vez no duplica nada.
        PhotoSection::zonesFor($companyId, 'work_order');
        $this->assertSame(5, PhotoSection::withoutGlobalScopes()->where('company_id', $companyId)->count());

        // Ya no existe la pantalla para crear, renombrar u ordenar secciones.
        $this->actingInPanel($this->a['admin']);
        $this->get('/admin/secciones-de-fotos')->assertNotFound();

        // Una sección propia de antes (sin zona): sus fotos se conservan y se ven,
        // pero no acepta fotos nuevas.
        $visit = $this->openVisit();
        $legacy = PhotoSection::withoutGlobalScopes()->forceCreate(['company_id' => $companyId, 'name' => 'Puertas de piso', 'position' => 9, 'is_active' => true]);
        $old = new VisitPhoto;
        $old->forceFill([
            'company_id' => $companyId, 'building_id' => $this->a['building']->id, 'building_visit_id' => $visit->id,
            'photo_section_id' => $legacy->id, 'section_name' => 'Puertas de piso', 'assignment_type' => 'maintenance', 'stage' => 'before',
            'batch' => (string) Str::uuid(), 'path' => 'visits/'.$companyId.'/vieja.jpg', 'uploaded_by' => $this->a['technician']->id, 'attached_at' => now(),
        ])->save();
        Storage::disk('local')->put($old->path, 'x');

        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/visits/{$visit->id}")->assertOk()
            ->assertSee('Puertas de piso')->assertSee($old->thumbUrl(), false)
            ->assertSee('Techo de cabina')->assertSee('Cabina y puertas');
        $this->upload(['visit_id' => $visit->id], ['photo_section_id' => $legacy->id])->assertStatus(422)
            ->assertJsonValidationErrors(['photo' => 'Elegí una zona válida para este trabajo.']);
        // «Trabajo realizado» es de órdenes de trabajo, no de mantenimientos.
        $workZone = PhotoSection::zonesFor($companyId, 'work_order')->firstWhere('code', 'trabajo');
        $this->upload(['visit_id' => $visit->id], ['photo_section_id' => $workZone->id])->assertStatus(422);
        $this->upload(['visit_id' => $visit->id], ['photo_section_id' => $this->section('Techo')->id])->assertCreated();
        $this->assertSame('Techo de cabina', VisitPhoto::withoutGlobalScopes()->latest('id')->first()->section_name);
    }

    public function test_sections_created_before_the_system_zones_become_those_zones(): void
    {
        $companyId = $this->a['company']->id;
        DB::table('photo_sections')->where('company_id', $companyId)->delete();
        $techo = PhotoSection::withoutGlobalScopes()->forceCreate(['company_id' => $companyId, 'name' => 'Techo de cabina', 'position' => 1, 'is_active' => false]);

        $zones = PhotoSection::zonesFor($companyId, 'maintenance');

        // La sección vieja con el nombre de la zona pasa a ser esa zona (sus fotos quedan agrupadas).
        $this->assertSame($techo->id, $zones->firstWhere('code', 'techo')->id);
        $this->assertTrue($techo->fresh()->is_active);
        $this->assertSame(4, PhotoSection::withoutGlobalScopes()->where('company_id', $companyId)->count());
    }
}
