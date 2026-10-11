<?php

namespace Tests\Feature\Reports;

use App\Models\Client;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\ReportVideo;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\Reports\ReportPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Fotos y videos de reportes: optimización, permisos, planes y archivos
 * inválidos. Disco privado siempre.
 */
class ReportMediaTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        config(['media.ffmpeg' => '', 'media.ffprobe' => '']); // sin FFmpeg (como el entorno de pruebas)
    }

    private function onPlan(string $slug): void
    {
        $plan = SubscriptionPlan::findBySlug($slug);
        Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-'.$this->a['company']->id, 'plan' => $slug,
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $this->a['company']->forgetPlan();
    }

    /** MP4 mínimo válido por contenido (caja ftyp). */
    private function mp4(string $name = 'falla.mp4', int $kb = 64): UploadedFile
    {
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", $kb * 1024);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function createReport(array $extra = [], ?User $as = null)
    {
        $as ??= $this->a['technician'];

        return $this->actingAs($as)->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1',
            'description' => 'Puerta de cabina no cierra', 'priority' => 'media', ...$extra,
        ]);
    }

    public function test_photos_get_a_thumbnail_and_lose_their_metadata(): void
    {
        // JPEG con un bloque EXIF falso que contiene una "ubicación".
        $image = imagecreatetruecolor(3000, 2000);
        ob_start();
        imagejpeg($image);
        $jpeg = ob_get_clean();
        $exif = "Exif\x00\x00MM\x00\x2A".'GPS-UBICACION-SECRETA';
        $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($exif) + 2).$exif.substr($jpeg, 2);

        $this->createReport(['photos' => [UploadedFile::fake()->createWithContent('foto.jpg', $jpeg)]])->assertSessionHasNoErrors();

        $photo = ReportPhoto::withoutGlobalScopes()->sole();
        $this->assertNotNull($photo->thumb_path);
        $stored = Storage::disk('local')->get($photo->path);
        $this->assertStringNotContainsString('GPS-UBICACION-SECRETA', $stored);
        $this->assertSame([2000, 1333], array_slice(getimagesizefromstring($stored), 0, 2)); // achicada, sin deformar
        $this->assertLessThanOrEqual(480, max(array_slice(getimagesizefromstring(Storage::disk('local')->get($photo->thumb_path)), 0, 2)));

        $this->actingAs($this->a['admin'])->get($photo->url().'?thumb=1')->assertOk()->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_old_photos_get_their_thumbnail_once(): void
    {
        $this->createReport(['photos' => [UploadedFile::fake()->image('vieja.jpg', 1200, 900)]]);
        $photo = ReportPhoto::withoutGlobalScopes()->sole();
        Storage::disk('local')->delete($photo->thumb_path);
        $photo->forceFill(['thumb_path' => null])->saveQuietly(); // como una foto anterior a las miniaturas

        $first = app(ReportPhotoService::class)->thumbnail($photo->fresh());
        $this->assertNotNull($first);
        $this->assertSame($first, $photo->fresh()->thumb_path);
        $mtime = filemtime(Storage::disk('local')->path($first));
        $this->travel(5)->minutes();
        $this->assertSame($first, app(ReportPhotoService::class)->thumbnail($photo->fresh()));
        $this->assertSame($mtime, filemtime(Storage::disk('local')->path($first))); // no se reprocesa
    }

    public function test_professional_plan_attaches_one_private_video_that_streams_with_ranges(): void
    {
        $this->onPlan('profesional');
        $this->createReport(['video' => $this->mp4()])->assertSessionHasNoErrors();

        $video = ReportVideo::withoutGlobalScopes()->sole();
        $this->assertSame(['video/mp4', ReportVideo::READY], [$video->mime, $video->status]);
        $this->assertStringStartsWith('reports/'.$this->a['company']->id.'/videos/', $video->path);
        $this->assertStringContainsString('sin comprimir', (string) $video->processing_note);

        $report = Report::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['technician'])->get(route('reports.video', $report))->assertOk()->assertHeader('Accept-Ranges', 'bytes');
        $this->get(route('reports.video', $report), ['Range' => 'bytes=0-99'])->assertStatus(206);
        $this->get(route('reports.video', ['report' => $report, 'download' => 1]))->assertHeader('Content-Disposition', 'attachment; filename="video-reporte-'.$report->id.'.mp4"');

        // Permisos: otro técnico, otra empresa, sin sesión, URL pública.
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($other)->get(route('reports.video', $report))->assertNotFound();
        $this->actingAs($this->b['admin'])->get(route('reports.video', $report))->assertNotFound();
        auth()->logout();
        $this->get(route('reports.video', $report))->assertRedirect('/login');
        $this->get('/storage/'.$video->path)->assertNotFound();

        // Un solo video por reporte.
        $this->actingAs($this->a['admin'])->post(route('reports.video.store', $report), ['video' => $this->mp4('otro.mp4')])->assertSessionHasErrors('video');

        // Quitar borra el archivo.
        $this->delete(route('reports.video.destroy', $report))->assertRedirect();
        Storage::disk('local')->assertMissing($video->path);
        $this->assertSame(0, ReportVideo::withoutGlobalScopes()->count());
    }

    public function test_initial_plan_cannot_attach_videos_even_with_a_crafted_request(): void
    {
        $this->onPlan('inicial');

        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/reports/create")->assertOk()->assertDontSee('name="video"', false);
        $this->createReport(['video' => $this->mp4()])->assertSessionHasErrors('video');
        $this->assertSame(0, Report::withoutGlobalScopes()->count()); // no se crea a medias
        $this->assertSame([], Storage::disk('local')->allFiles());

        $report = Report::factory()->create(['building_id' => $this->a['building']->id, 'user_id' => $this->a['technician']->id]);
        $this->actingAs($this->a['admin'])->post(route('reports.video.store', $report), ['video' => $this->mp4()])->assertSessionHasErrors('video');
        $this->assertSame(0, ReportVideo::withoutGlobalScopes()->count());
    }

    public function test_fake_or_oversized_videos_are_rejected(): void
    {
        $this->onPlan('empresa');

        $this->createReport(['video' => UploadedFile::fake()->createWithContent('virus.mp4', '<?php echo "hola"; ?>'.str_repeat('x', 2048))])->assertSessionHasErrors('video');
        config(['media.video_max_mb' => 1]);
        $this->createReport(['video' => $this->mp4('grande.mp4', 1500)])->assertSessionHasErrors('video');
        $this->assertSame(0, ReportVideo::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_portal_plays_the_video_only_of_shared_reports(): void
    {
        $this->onPlan('profesional');
        $this->createReport(['video' => $this->mp4()]);
        $report = Report::withoutGlobalScopes()->sole();

        auth()->logout();
        $client = Client::factory()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->update(['client_id' => $client->id]);
        $portal = User::factory()->create();
        $portal->forceFill(['role' => User::ROLE_CLIENT, 'company_id' => $this->a['company']->id, 'client_id' => $client->id])->save();
        $portal->portalBuildings()->sync([$this->a['building']->id]);

        $this->actingAs($portal)->get(route('portal.report-video', $report))->assertNotFound(); // privado
        $report->shareWithClient(true);
        $this->get(route('portal.report-video', $report))->assertOk();
        $this->get(route('portal.report', $report))->assertOk()->assertSee(route('portal.report-video', $report), false);
        $this->get(route('reports.video', $report))->assertNotFound(); // la ruta del panel no es para clientes
    }
}
