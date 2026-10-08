<?php

namespace Tests\Feature\Flows;

use App\Enums\PlanLimit;
use App\Filament\Resources\Reports\Pages\EditReport;
use App\Filament\Resources\Reports\RelationManagers\PhotosRelationManager;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Services\Reports\ReportPhotoService;
use App\Support\Plans\PlanGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reportes con varias fotos (opcionales): tamaños, orientaciones, formatos,
 * límites, permisos, borrado sin huérfanos y reportes viejos de una foto.
 */
class ReportPhotosTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        // GD o Imagick siempre están en producción (Dockerfile / Forge).
        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Requiere Imagick o GD (procesa la foto antes de guardar).');
        }

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->makeTenant();
    }

    private function send(array $photos = [], string $description = 'La puerta de cabina no cierra bien.', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => $description,
            'priority' => 'alta',
            'photos' => $photos,
        ]);
    }

    private function img(string $name, int $w = 800, int $h = 600): UploadedFile
    {
        return UploadedFile::fake()->image($name, $w, $h);
    }

    public function test_zero_one_and_several_photos(): void
    {
        $this->send([])->assertSessionHasNoErrors();
        $this->assertSame(0, Report::latest('id')->first()->photos()->count());

        $this->send([$this->img('una.jpg')])->assertSessionHasNoErrors();
        $this->assertSame(1, Report::latest('id')->first()->photos()->count());

        $this->send([$this->img('a.jpg'), $this->img('b.png'), $this->img('c.webp')])->assertSessionHasNoErrors();
        $report = Report::latest('id')->first();
        $this->assertSame([0, 1, 2], $report->photos->pluck('position')->all());

        $this->assertSame(3, Report::count());
        $this->assertCount(4, Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_maximum_photos_per_report(): void
    {
        $max = ReportPhotoService::MAX_PHOTOS;

        $this->send(array_map(fn ($i) => $this->img("f{$i}.jpg", 300, 200), range(1, $max)))->assertSessionHasNoErrors();
        $this->assertSame($max, Report::sole()->photos()->count());

        $this->send(array_map(fn ($i) => $this->img("g{$i}.jpg", 300, 200), range(1, $max + 1)))->assertSessionHasErrors('photos');
        $this->assertSame(1, Report::count());

        // El servicio también lo impide al agregar a un reporte ya completo.
        $this->expectException(ValidationException::class);
        app(ReportPhotoService::class)->add(Report::sole(), [$this->img('extra.jpg')]);
    }

    public function test_photos_of_every_shape_and_size_are_stored_light_and_upright(): void
    {
        $cases = [
            'vertical.jpg' => [[1200, 1600], [1200, 1600]],
            'horizontal.jpg' => [[1600, 1200], [1600, 1200]],
            'enorme.jpg' => [[6000, 4000], [2000, 1333]],          // se achica sin deformar
            'enorme-vertical.png' => [[3000, 5000], [1200, 2000]],
            'chica.png' => [[320, 240], [320, 240]],               // nunca se agranda
        ];

        $this->send(array_map(fn ($name) => $this->img($name, ...$cases[$name][0]), array_keys($cases)))->assertSessionHasNoErrors();

        foreach (Report::sole()->photos as $i => $photo) {
            [$w, $h] = array_values($cases)[$i][1];
            $size = getimagesize(Storage::disk('local')->path($photo->path));

            $this->assertSame([$w, $h], [$size[0], $size[1]], array_keys($cases)[$i]);
            $this->assertSame([$w, $h], [$photo->width, $photo->height]);
            $this->assertSame('image/jpeg', $size['mime']);                       // siempre re-codificada
            $this->assertMatchesRegularExpression('#^reports/'.$this->a['company']->id.'/[A-Za-z0-9]{40}\.jpg$#', $photo->path);
            $this->assertLessThan(2_000_000, $photo->size);
        }
    }

    public function test_invalid_and_too_large_files_never_create_a_report_or_leave_files(): void
    {
        // Contenido roto con extensión de imagen.
        $this->send([$this->img('ok.jpg'), UploadedFile::fake()->createWithContent('rota.jpg', str_repeat("\xFF\xD8\xFF\x00basura", 50))])
            ->assertSessionHasErrors('photos');

        // No es imagen.
        $this->send([UploadedFile::fake()->createWithContent('doc.pdf', '%PDF-1.4')->mimeType('application/pdf')])
            ->assertSessionHasErrors('photos.0');

        // Más de 10 MB.
        $this->send([UploadedFile::fake()->image('pesada.jpg', 100, 100)->size(11000)])->assertSessionHasErrors('photos.0');

        $this->assertSame(0, Report::count());
        $this->assertSame(0, ReportPhoto::count());
        $this->assertSame([], Storage::disk('local')->allFiles()); // ni la foto buena quedó suelta
    }

    public function test_long_and_short_texts(): void
    {
        $long = trim(str_repeat('El ascensor hace un ruido metálico al frenar en el piso 4. ', 80));

        $this->send([], $long)->assertSessionHasNoErrors();
        $this->assertSame($long, Report::sole()->description);

        $this->send([], 'mal')->assertSessionHasErrors('description');
        $this->send([], str_repeat('x', 5001))->assertSessionHasErrors('description');
        $this->assertSame(1, Report::count());
    }

    public function test_who_can_see_each_photo(): void
    {
        $this->send([$this->img('a.jpg'), $this->img('b.jpg')]);
        $report = Report::sole();
        [$first, $second] = $report->photos->all();

        // La otra empresa se crea sin sesión (logueado, los modelos toman la
        // empresa del usuario actual).
        auth()->logout();
        $b = $this->makeTenant();
        $this->assertNotSame($this->a['company']->id, $b['admin']->company_id);
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        // Autor y admin de la empresa: sí (todas las fotos, y el link viejo = la primera).
        foreach ([$this->a['technician'], $this->a['admin']] as $user) {
            $this->actingAs($user)->get($first->url())->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->actingAs($user)->get($second->url())->assertOk();
            $this->actingAs($user)->get(route('reports.photo', $report))->assertOk();
        }

        // Otro técnico de la misma empresa y cualquiera de otra empresa: 404.
        foreach (['colega' => $colleague, 'admin B' => $b['admin'], 'técnico B' => $b['technician']] as $who => $user) {
            $this->assertSame(404, $this->actingAs($user)->get($first->url())->getStatusCode(), $who.' foto');
            $this->assertSame(404, $this->actingAs($user)->get(route('reports.photo', $report))->getStatusCode(), $who.' link viejo');
        }

        // Foto de un reporte usada con el ID de otro reporte: 404.
        $other = Report::factory()->withPhoto()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $this->actingAs($this->a['admin'])->get(route('reports.photos.show', ['report' => $other->id, 'photo' => $first->id]))->assertNotFound();

        // Sin sesión, ni por la ruta ni por /storage.
        auth()->logout();
        $this->get($first->url())->assertRedirect('/login');
        $this->get('/storage/'.$first->path)->assertNotFound();
    }

    public function test_deleting_photos_removes_their_files_and_never_leaves_orphans(): void
    {
        $this->send([$this->img('a.jpg'), $this->img('b.jpg'), $this->img('c.jpg')]);
        $report = Report::sole();
        [$a, $b, $c] = $report->photos->all();

        // Desde el panel (admin).
        $this->actingInPanel($this->a['admin']);
        Livewire::test(PhotosRelationManager::class, ['ownerRecord' => $report, 'pageClass' => EditReport::class])
            ->callTableAction('deletePhoto', $b);

        Storage::disk('local')->assertMissing($b->path);
        $this->assertSame([$a->id, $c->id], $report->photos()->pluck('id')->all());
        $this->assertSame([0, 1], $report->photos()->pluck('position')->all()); // reordenadas

        // Borrado común del reporte: es historial, las fotos se conservan.
        $report->delete();
        Storage::disk('local')->assertExists($a->path);

        // Borrado definitivo: se van fotos y archivos.
        $report->forceDelete();
        $this->assertSame(0, ReportPhoto::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_admin_adds_photos_from_the_panel_up_to_the_limit(): void
    {
        $this->send([$this->img('a.jpg')]);
        $report = Report::sole();
        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->fillForm(['new_photos' => [$this->img('panel.png', 900, 1200)], 'observations' => 'Se pidió el repuesto.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $report->refresh();
        $this->assertSame(2, $report->photos()->count());
        $this->assertSame('Se pidió el repuesto.', $report->observations);
        $this->assertSame([900, 1200], [$report->photos->last()->width, $report->photos->last()->height]);
    }

    public function test_old_single_photo_reports_keep_working_and_the_migration_preserves_them(): void
    {
        // Reporte de antes: foto en la columna vieja, sin filas en report_photos.
        $path = 'reports/'.$this->a['company']->id.'/'.str_repeat('v', 40).'.jpg';
        Storage::disk('public')->put($path, $this->img('vieja.jpg')->getContent());
        $old = Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        DB::table('reports')->where('id', $old->id)->update(['photo' => $path]);

        // Reporte nuevo con dos fotos.
        $this->send([$this->img('n1.jpg'), $this->img('n2.jpg')]);
        $new = Report::latest('id')->first();
        $newPaths = $new->photos->pluck('path')->all();

        $migration = require database_path('migrations/2026_10_16_100000_create_report_photos_table.php');

        // Rollback: el reporte nuevo recupera su primera foto en la columna vieja; no se borra ningún archivo.
        $migration->down();
        $this->assertSame($newPaths[0], DB::table('reports')->where('id', $new->id)->value('photo'));
        $this->assertSame($path, DB::table('reports')->where('id', $old->id)->value('photo'));
        foreach ($newPaths as $p) {
            Storage::disk('local')->assertExists($p);
        }

        // Volver a migrar: cada foto de la columna vieja pasa a report_photos.
        $migration->up();
        $this->assertSame([$path], $old->photos()->pluck('path')->all());
        $this->assertSame($this->a['company']->id, $old->photos()->first()->company_id);

        // Se ve por la ruta protegida y se puede mover al disco privado.
        $this->actingAs($this->a['admin'])->get(route('reports.photo', $old))->assertOk();
        $this->artisan('reports:move-photos-private')->assertSuccessful();
        Storage::disk('public')->assertMissing($path);
        Storage::disk('local')->assertExists($path);
        $this->actingAs($this->a['admin'])->get($old->photos()->first()->url())->assertOk();
    }

    public function test_photos_do_not_change_the_monthly_report_count(): void
    {
        $this->send([$this->img('a.jpg'), $this->img('b.jpg'), $this->img('c.jpg')]);

        $this->assertSame(1, PlanGuard::for($this->a['company'])->usage(PlanLimit::ReportsPerMonth));
    }
}
