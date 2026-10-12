<?php

namespace Tests\Feature\Visits;

use App\Filament\Resources\PhotoSections\Pages\ManagePhotoSections;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\PhotoSection;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Services\Visits\VisitPhotoService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Fotos de antes y después de mantenimientos e inspecciones: se suben de a
 * una mientras se completa el remito, se vinculan al firmar, viven en el
 * disco privado y solo las ven el admin de la empresa y los técnicos de la
 * visita (nunca el portal ni el enlace público ni el PDF).
 */
class VisitPhotosTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private string $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->a['building']->update(['elevator_count' => 2]);
        $this->batch = (string) Str::uuid();
    }

    private function section(string $name = 'Pozo', ?int $companyId = null): PhotoSection
    {
        PhotoSection::forCompany($companyId ?? $this->a['company']->id);

        return PhotoSection::withoutGlobalScopes()->where('company_id', $companyId ?? $this->a['company']->id)->where('name', $name)->firstOrFail();
    }

    private function upload(array $overrides = [], ?User $as = null, ?UploadedFile $file = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])->postJson("/{$this->a['company']->slug}/visit-photos", [
            'batch' => $this->batch,
            'building_id' => $this->a['building']->id,
            'assignment_type' => 'maintenance',
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
            'photo_batch' => $this->batch,
            ...$overrides,
        ]);
    }

    public function test_the_technician_uploads_while_filling_the_remito_and_it_is_linked_on_sign(): void
    {
        $elevator = Elevator::where('building_id', $this->a['building']->id)->first();
        $this->upload()->assertCreated()->assertJsonStructure(['id', 'thumb_url', 'delete_url']);
        $this->upload(['stage' => 'after'])->assertCreated();

        $photo = VisitPhoto::withoutGlobalScopes()->first();
        $this->assertNull($photo->building_visit_id);
        $this->assertStringStartsWith('visits/'.$this->a['company']->id.'/', $photo->path);
        Storage::disk('local')->assertExists([$photo->path, $photo->thumb_path]);
        $this->assertSame('image/jpeg', getimagesizefromstring(Storage::disk('local')->get($photo->path))['mime']);

        $section = $this->section();
        $this->sign(['photo_notes' => [$section->id => 'Pozo con agua'], 'photo_elevators' => [$section->id => $elevator->id]])->assertSessionHasNoErrors();

        $note = DeliveryNote::sole();
        $photos = VisitPhoto::withoutGlobalScopes()->get();
        $this->assertCount(2, $photos);
        foreach ($photos as $p) {
            $this->assertSame($note->building_visit_id, $p->building_visit_id);
            $this->assertSame($note->id, $p->delivery_note_id);
            $this->assertSame('Pozo con agua', $p->notes);
            $this->assertSame($elevator->id, $p->elevator_id);
            $this->assertSame('Pozo', $p->section_name);
            $this->assertSame($this->a['technician']->id, $p->uploaded_by);
            $this->assertNotNull($p->attached_at);
        }
    }

    public function test_photos_are_optional(): void
    {
        $this->sign(['photo_batch' => null])->assertSessionHasNoErrors();
        $this->assertSame(1, DeliveryNote::count());
        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_three_photos_per_section_and_stage(): void
    {
        foreach (range(1, 3) as $i) {
            $this->upload()->assertCreated();
        }
        $this->upload()->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->upload(['stage' => 'after'])->assertCreated();
        $this->upload(['photo_section_id' => $this->section('Techo')->id])->assertCreated();
        $this->assertSame(5, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_thirty_photos_and_the_total_size_per_visit(): void
    {
        $fake = fn (int $size) => [
            'company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'photo_section_id' => $this->section('Techo')->id,
            'section_name' => 'Techo', 'assignment_type' => 'maintenance', 'stage' => 'after', 'batch' => $this->batch,
            'path' => 'visits/'.$this->a['company']->id.'/'.Str::random(20).'.jpg', 'size' => $size, 'uploaded_by' => $this->a['technician']->id,
            'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('visit_photos')->insert(array_map(fn () => $fake(1000), range(1, 30)));
        $this->upload()->assertStatus(422)->assertJsonValidationErrors(['photo' => 'Podés cargar hasta 30 fotos por visita.']);

        DB::table('visit_photos')->delete();
        DB::table('visit_photos')->insert($fake(VisitPhoto::MAX_TOTAL_BYTES));
        $this->upload()->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('visits')); // lo rechazado no deja archivos
    }

    public function test_formats_size_and_fake_images_are_rejected_without_leaving_files(): void
    {
        $this->upload([], null, UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'))->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->upload([], null, UploadedFile::fake()->image('enorme.jpg')->size(VisitPhoto::MAX_KB + 1))->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->upload([], null, UploadedFile::fake()->createWithContent('falsa.jpg', '<?php echo "no soy una imagen";'))->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_only_assigned_buildings_and_sections_of_the_company(): void
    {
        $other = Building::factory()->create(['company_id' => $this->a['company']->id]);
        $this->upload(['building_id' => $other->id])->assertForbidden();
        $this->upload(['assignment_type' => 'inspection'])->assertForbidden(); // asignado solo a mantenimiento

        auth()->logout();
        $b = $this->makeTenant();
        $this->upload(['building_id' => $b['building']->id])->assertNotFound();
        $this->upload(['photo_section_id' => $this->section('Pozo', $b['company']->id)->id])->assertNotFound();

        $inactive = $this->section('Techo');
        $inactive->forceFill(['is_active' => false])->save();
        $this->upload(['photo_section_id' => $inactive->id])->assertNotFound();

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_who_can_see_the_photos(): void
    {
        $this->upload()->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $url = "/files/visit-photos/{$photo->id}";

        // En preparación: solo quien la subió.
        $this->actingAs($this->a['technician'])->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->a['admin'])->get($url)->assertNotFound();

        $this->sign()->assertSessionHasNoErrors();
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($this->a['admin'])->get($url)->assertOk();
        $this->actingAs($this->a['technician'])->get($url.'?thumb=1')->assertOk();
        $this->actingAs($colleague)->get($url)->assertNotFound(); // no participó de la visita

        auth()->logout();
        $b = $this->makeTenant();
        $this->actingAs($b['admin'])->get($url)->assertNotFound();
        $this->actingAs($b['technician'])->get($url)->assertNotFound();

        $client = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $client->portalBuildings()->sync([$this->a['building']->id]);
        $this->actingAs($client)->get($url)->assertNotFound();

        auth()->logout();
        $this->get($url)->assertRedirect();
    }

    public function test_photos_show_on_the_internal_remito_but_never_public_portal_or_pdf(): void
    {
        $this->upload()->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $note = DeliveryNote::sole();
        $note->forceFill(['shared_with_client' => true])->save();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")->assertOk()
            ->assertSee('data-visit-photos', false)->assertSee($photo->thumbUrl(), false);
        $this->actingAs($this->a['admin'])->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")->assertOk()
            ->assertSee('data-visit-photos', false);

        auth()->logout();
        $this->get(route('delivery-notes.public', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->assertOk()
            ->assertDontSee('visit-photos')->assertDontSee('data-visit-photos', false);
        $pdf = $this->get(route('delivery-notes.public.pdf', ['company' => $this->a['company']->slug, 'token' => $note->public_token]))->getContent();
        $this->assertStringNotContainsString('visit-photos', $pdf);
        $this->assertStringNotContainsString('Fotos de antes', $pdf);

        $client = User::factory()->create(['email' => 'cliente@consorcio.test']);
        $client->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $this->a['building']->client_id])->save();
        $client->portalBuildings()->sync([$this->a['building']->id]);
        $this->actingAs($client)->get(route('portal.delivery-note', $note))->assertOk()->assertDontSee('visit-photos');
    }

    public function test_changing_ids_never_reaches_another_photo_or_attaches_foreign_ones(): void
    {
        // Lote de OTRO técnico con el mismo id de lote: no se vincula a mi remito.
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($colleague->id, ['type' => 'maintenance']);
        $this->upload([], $colleague)->assertCreated();

        $this->sign()->assertSessionHasNoErrors();
        $this->assertNull(VisitPhoto::withoutGlobalScopes()->sole()->building_visit_id);

        // Ni borrarla ni verla con su id.
        $id = VisitPhoto::withoutGlobalScopes()->sole()->id;
        $this->actingAs($this->a['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$id}")->assertNotFound();
        $this->actingAs($this->a['technician'])->get("/files/visit-photos/{$id}")->assertNotFound();
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_removing_and_pruning_never_leave_orphan_files(): void
    {
        $id = $this->upload()->assertCreated()->json('id');
        $photo = VisitPhoto::withoutGlobalScopes()->find($id);
        $this->actingAs($this->a['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$id}")->assertOk();
        Storage::disk('local')->assertMissing([$photo->path, $photo->thumb_path]);

        // Una vinculada no se puede quitar desde el formulario.
        $this->upload()->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $attached = VisitPhoto::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$attached->id}")->assertNotFound();

        // En preparación y abandonada: se borra a las 24 h con su archivo; la vinculada queda.
        $this->batch = (string) Str::uuid();
        $this->upload()->assertCreated();
        $abandoned = VisitPhoto::withoutGlobalScopes()->whereNull('building_visit_id')->sole();
        $this->travel(25)->hours();
        $this->artisan('visit-photos:prune')->expectsOutputToContain('Fotos sin vincular borradas: 1')->assertSuccessful();

        $this->assertNull(VisitPhoto::withoutGlobalScopes()->find($abandoned->id));
        Storage::disk('local')->assertMissing($abandoned->path);
        Storage::disk('local')->assertExists($attached->path);
    }

    public function test_sections_are_per_company_configurable_and_deactivating_keeps_history(): void
    {
        $this->assertSame(PhotoSection::DEFAULTS, PhotoSection::forCompany($this->a['company']->id)->pluck('name')->all());
        PhotoSection::forCompany($this->a['company']->id); // no duplica
        $this->assertSame(3, PhotoSection::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ManagePhotoSections::class)
            ->callAction('create', data: ['name' => 'Cabina', 'position' => 4])
            ->assertHasNoActionErrors();
        Livewire::test(ManagePhotoSections::class)
            ->callAction('create', data: ['name' => 'Cabina', 'position' => 5])
            ->assertHasActionErrors(['name' => 'unique']);
        $this->assertSame($this->a['company']->id, PhotoSection::where('name', 'Cabina')->sole()->company_id);

        $this->upload()->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $pozo = $this->section();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ManagePhotoSections::class)->callTableAction('toggle', $pozo);
        $this->assertFalse($pozo->fresh()->is_active);

        // Las fotos históricas se conservan y siguen a la vista; la sección ya no se ofrece.
        $note = DeliveryNote::sole();
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")->assertSee('Pozo');
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertNotContains('Pozo', PhotoSection::forCompany($this->a['company']->id)->pluck('name')->all());

        // Otra empresa no ve estas secciones.
        auth()->logout();
        $b = $this->makeTenant();
        $this->actingInPanel($b['admin']);
        Livewire::test(ManagePhotoSections::class)->assertCanNotSeeTableRecords([PhotoSection::withoutGlobalScopes()->where('name', 'Cabina')->sole()]);
    }

    public function test_the_remito_form_offers_photos_for_visits_and_keeps_them_after_a_validation_error(): void
    {
        $url = "/{$this->a['company']->slug}/delivery-notes/create/building/{$this->a['building']->id}?assignment_type=maintenance&month=10&year=2026";
        $this->actingAs($this->a['technician'])->get($url)->assertOk()
            ->assertSee('id="visitPhotos"', false)->assertSee('Sala de máquinas')->assertSee('Equipo (opcional)');

        $this->upload()->assertCreated();
        $this->from($url)->sign(['description' => ''])->assertSessionHasErrors('description');
        $this->get($url)->assertSee('value="'.$this->batch.'"', false)
            ->assertSee(VisitPhoto::withoutGlobalScopes()->sole()->thumbUrl(), false);
    }

    public function test_visit_photos_lose_their_gps_metadata(): void
    {
        $image = imagecreatetruecolor(800, 600);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $exif = "Exif\x00\x00MM\x00\x2A".'GPS-UBICACION-SECRETA';
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->upload([], null, UploadedFile::fake()->createWithContent('pozo.jpg', $jpeg))->assertCreated();

        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $this->assertStringNotContainsString('GPS-UBICACION-SECRETA', Storage::disk('local')->get($photo->path));
        $this->assertStringNotContainsString('GPS-UBICACION-SECRETA', Storage::disk('local')->get($photo->thumb_path));
        $this->assertStringStartsWith('visits/'.$this->a['company']->id.'/', $photo->path);
    }

    public function test_images_huge_in_pixels_are_rejected_before_decoding(): void
    {
        config(['media.photo_max_pixels' => 100 * 100]);

        $this->upload([], null, UploadedFile::fake()->image('enorme.png', 200, 200))->assertStatus(422)->assertJsonValidationErrors('photo');

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_retried_upload_with_the_same_identity_is_not_duplicated(): void
    {
        // La subida se guardó pero la respuesta no llegó: «Reintentar» manda lo mismo.
        $first = $this->upload(['client_key' => 'foto-1'])->assertCreated()->json('id');
        $again = $this->upload(['client_key' => 'foto-1'])->assertOk()->json('id');

        $this->assertSame($first, $again);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertCount(2, Storage::disk('local')->allFiles()); // la foto y su miniatura, una sola vez

        // Otra foto (otra identidad) sí se agrega; una identidad rara se rechaza.
        $this->upload(['client_key' => 'foto-2'])->assertCreated();
        $this->upload(['client_key' => '../../x'])->assertStatus(422);
        $this->assertSame(2, VisitPhoto::withoutGlobalScopes()->count());

        // La misma identidad de lote y foto desde otro técnico no devuelve la mía.
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($colleague->id, ['type' => 'maintenance']);
        $this->upload(['client_key' => 'foto-1'], $colleague)->assertStatus(422);
    }

    public function test_a_photo_finishing_after_the_remito_is_signed_is_rejected_and_history_cannot_be_removed(): void
    {
        $id = $this->upload()->assertCreated()->json('id');
        $this->sign(['photo_count' => 1])->assertSessionHasNoErrors()->assertSessionMissing('warning');

        // Una subida que termina después de firmar no queda colgada en el lote.
        $this->upload(['stage' => 'after'])->assertStatus(422)->assertJsonValidationErrors(['photo' => 'Este formulario ya no admite fotos. Recargá la página.']);

        // Quitarla desde el formulario (p. ej. un toque tardío) ya no la borra.
        $this->actingAs($this->a['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$id}")->assertNotFound();
        $photo = VisitPhoto::withoutGlobalScopes()->find($id);
        $this->assertNotNull($photo->building_visit_id);
        Storage::disk('local')->assertExists($photo->path);
    }

    public function test_the_remito_warns_when_uploaded_photos_were_not_kept(): void
    {
        $this->upload()->assertCreated();
        $this->travel(25)->hours();
        $this->artisan('visit-photos:prune')->assertSuccessful(); // el borrador quedó abierto más de 24 h
        $this->upload(['stage' => 'after'])->assertCreated();

        $this->sign(['photo_count' => 2])->assertSessionHasNoErrors()
            ->assertSessionHas('warning', fn ($message) => str_contains($message, '1 foto no quedó guardada'));

        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->whereNotNull('building_visit_id')->count());
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes")->assertSee('1 foto no quedó guardada');
    }

    public function test_pruning_rechecks_each_photo_and_one_failure_does_not_stop_the_rest(): void
    {
        $this->upload()->assertCreated();
        $this->upload(['stage' => 'after'])->assertCreated();
        [$broken, $ok] = VisitPhoto::withoutGlobalScopes()->orderBy('id')->get()->all();
        $this->travel(25)->hours();

        VisitPhoto::deleting(function (VisitPhoto $photo) use ($broken) {
            if ($photo->id === $broken->id) {
                throw new \RuntimeException('disco no disponible');
            }
        });

        $this->artisan('visit-photos:prune')
            ->expectsOutputToContain('Fotos sin vincular borradas: 1')
            ->expectsOutputToContain('No se pudieron borrar: 1')
            ->assertFailed();

        $this->assertNotNull(VisitPhoto::withoutGlobalScopes()->find($broken->id)); // queda para la próxima corrida
        $this->assertNull(VisitPhoto::withoutGlobalScopes()->find($ok->id));
        Storage::disk('local')->assertMissing($ok->path);

        // Una foto que se vincula (remito firmado) DESPUÉS de que la limpieza
        // la eligió y antes de borrarla: se vuelve a verificar y no se toca.
        $this->batch = (string) Str::uuid();
        $this->upload()->assertCreated();
        $late = VisitPhoto::withoutGlobalScopes()->latest('id')->first();
        $this->travel(25)->hours();
        $visitId = BuildingVisit::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id, 'month' => 9, 'year' => 2026])->id;
        $signed = false;
        VisitPhoto::retrieved(function (VisitPhoto $photo) use ($late, $visitId, &$signed) {
            if (! $signed && $photo->id === $late->id) {
                $signed = true;
                DB::table('visit_photos')->where('id', $late->id)->update(['building_visit_id' => $visitId, 'attached_at' => now()]);
            }
        });

        $result = app(VisitPhotoService::class)->prune();

        $this->assertTrue($signed);
        $this->assertSame(['deleted' => 0, 'failed' => 1], $result); // la rota sigue fallando; la vinculada no se cuenta
        $this->assertSame($visitId, (int) $late->fresh()->building_visit_id);
        Storage::disk('local')->assertExists($late->path);
    }

    public function test_the_cleanup_runs_daily_from_the_laravel_scheduler(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'visit-photos:prune'));

        $this->assertNotNull($event, 'visit-photos:prune no está programado');
        $this->assertSame('45 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_a_technician_cannot_pile_up_photos_in_forms_that_are_never_signed(): void
    {
        $this->upload()->assertCreated();
        $template = VisitPhoto::withoutGlobalScopes()->sole()->getAttributes();
        $rows = collect(range(2, VisitPhotoService::MAX_STAGED_PER_USER))->map(fn ($i) => [
            ...Arr::except($template, ['id']), 'batch' => (string) Str::uuid(), 'path' => "visits/{$this->a['company']->id}/x{$i}.jpg", 'thumb_path' => null, 'client_key' => null,
        ])->all();
        DB::table('visit_photos')->insert($rows);

        $this->batch = (string) Str::uuid();
        $this->upload()->assertStatus(422)->assertJsonValidationErrors('photo');
        $this->assertCount(2, Storage::disk('local')->allFiles()); // solo la primera (y su miniatura)
    }

    public function test_manipulated_requests_never_reach_another_company(): void
    {
        $this->upload()->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();

        auth()->logout();
        $b = $this->makeTenant();

        // Borrar con el slug propio o el ajeno: nunca.
        $this->actingAs($b['technician'])->deleteJson("/{$b['company']->slug}/visit-photos/{$photo->id}")->assertNotFound();
        $this->actingAs($b['technician'])->deleteJson("/{$this->a['company']->slug}/visit-photos/{$photo->id}")->assertStatus(404);
        // Subir al lote o al edificio ajeno.
        $this->actingAs($b['technician'])->postJson("/{$b['company']->slug}/visit-photos", [
            'batch' => $this->batch, 'building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance',
            'photo_section_id' => $this->section()->id, 'stage' => 'before', 'photo' => UploadedFile::fake()->image('x.jpg'),
        ])->assertNotFound();
        // Firmar el remito de la otra empresa usando su lote.
        $this->actingAs($b['technician'])->post("/{$b['company']->slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id, 'description' => 'x', 'month' => 10, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'assignment_type' => 'maintenance',
            'signature_name' => 'T', 'signature' => $this->validSignature(), 'photo_batch' => $this->batch,
        ])->assertNotFound();
        // Ver la foto o el remito interno.
        $this->actingAs($b['admin'])->get("/files/visit-photos/{$photo->id}?thumb=1")->assertNotFound();
        $this->actingAs($b['admin'])->get("/{$this->a['company']->slug}/delivery-notes/".DeliveryNote::withoutGlobalScopes()->sole()->number)->assertStatus(404);

        // El archivo privado no se sirve por ninguna ruta pública conociendo su nombre.
        auth()->logout();
        $this->get('/storage/'.$photo->path)->assertNotFound();
        $this->get('/'.$photo->path)->assertNotFound();

        $this->assertSame($photo->building_visit_id, $photo->fresh()->building_visit_id);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->count());
    }

    public function test_a_super_admin_only_sees_photos_inside_the_selected_company(): void
    {
        $this->upload()->assertCreated();
        $this->sign()->assertSessionHasNoErrors();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        auth()->logout();
        $super = User::factory()->superAdmin()->create();

        session()->flush(); // sesión nueva del SuperAdmin
        $this->assertFalse($photo->canBeViewedBy($super)); // sin empresa elegida
        session(['selected_company_id' => $this->a['company']->id]);
        $this->assertTrue($photo->canBeViewedBy($super));
        auth()->logout();
        $b = $this->makeTenant();
        session(['selected_company_id' => $b['company']->id]);
        $this->assertFalse($photo->canBeViewedBy($super));
    }
}
