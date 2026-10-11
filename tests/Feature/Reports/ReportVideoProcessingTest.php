<?php

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\ReportVideo;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Reports\ReportVideoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Duración, tamaño, compresión y fallas de los videos con archivos reales
 * (tests/Fixtures/videos, generados con FFmpeg). Los casos de compresión se
 * omiten si la máquina no tiene FFmpeg (se informa como omitido, no aprobado).
 */
class ReportVideoProcessingTest extends TestCase
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
        config(['media.ffmpeg' => '', 'media.ffprobe' => '', 'media.video_max_seconds' => 90]);

        $plan = SubscriptionPlan::findBySlug('profesional');
        Subscription::updateOrCreate(['company_id' => $this->a['company']->id], [
            'provider' => 'mercadopago', 'provider_subscription_id' => 'PRE-'.$this->a['company']->id, 'plan' => 'profesional',
            'status' => Subscription::AUTHORIZED, 'amount' => $plan->price, 'currency' => 'ARS', 'current_period_end' => now()->addMonth(),
        ]);
        $this->a['company']->forgetPlan();
    }

    private function fixture(string $name, ?string $as = null): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($as ?? $name, (string) file_get_contents(base_path('tests/Fixtures/videos/'.$name)));
    }

    private function send(UploadedFile $video, bool $json = false)
    {
        $data = [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1',
            'description' => 'Ruido en el operador de puertas', 'priority' => 'media', 'video' => $video,
        ];

        return $json
            ? $this->actingAs($this->a['technician'])->postJson("/{$this->a['company']->slug}/reports", $data)
            : $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", $data);
    }

    private function ffmpeg(): void
    {
        $ffmpeg = trim((string) shell_exec('command -v ffmpeg'));
        $ffprobe = trim((string) shell_exec('command -v ffprobe'));
        if ($ffmpeg === '' || $ffprobe === '') {
            $this->markTestSkipped('FFmpeg no está instalado en esta máquina.');
        }
        config(['media.ffmpeg' => $ffmpeg, 'media.ffprobe' => $ffprobe]);
    }

    public function test_duration_is_read_on_the_server_without_ffprobe(): void
    {
        $service = app(ReportVideoService::class);

        $this->assertSame(3, $service->duration(base_path('tests/Fixtures/videos/corto.mp4'), 'video/mp4'));
        $this->assertSame(2, $service->duration(base_path('tests/Fixtures/videos/iphone.mov'), 'video/quicktime'));
        $this->assertSame(2, $service->duration(base_path('tests/Fixtures/videos/corto.webm'), 'video/webm'));
        $this->assertSame(95, $service->duration(base_path('tests/Fixtures/videos/largo.mp4'), 'video/mp4'));

        $this->send($this->fixture('corto.mp4'))->assertSessionHasNoErrors();
        $this->assertSame(3, ReportVideo::withoutGlobalScopes()->sole()->duration);
    }

    public function test_too_long_unreadable_or_oversized_videos_are_rejected_without_leaving_files(): void
    {
        $this->send($this->fixture('largo.mp4'))->assertSessionHasErrors(['video' => 'El video dura 95 segundos y el máximo es 90. Grabá uno más corto.']);

        // Encabezado de MP4 sin la caja con la duración (truncado / dañado).
        $broken = substr((string) file_get_contents(base_path('tests/Fixtures/videos/corto.mp4')), 0, 3000);
        $this->send(UploadedFile::fake()->createWithContent('roto.mp4', $broken))->assertSessionHasErrors('video');

        config(['media.video_max_mb' => 0]);
        $this->send($this->fixture('corto.mp4'))->assertSessionHasErrors('video');

        $this->assertSame(0, Report::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_upload_form_gets_json_errors_and_the_redirect(): void
    {
        $this->send($this->fixture('largo.mp4'), json: true)->assertStatus(422)
            ->assertJsonPath('message', 'El video dura 95 segundos y el máximo es 90. Grabá uno más corto.');

        $this->send($this->fixture('corto.mp4'), json: true)->assertOk()
            ->assertJsonPath('redirect', route('reports.index', ['company' => $this->a['company']->slug]));
        $this->assertSame(1, ReportVideo::withoutGlobalScopes()->count());
    }

    public function test_status_endpoint_follows_the_video_permissions(): void
    {
        $this->send($this->fixture('corto.mp4'));
        $report = Report::withoutGlobalScopes()->sole();

        $this->actingAs($this->a['technician'])->getJson(route('reports.video.status', $report))->assertOk()
            ->assertJsonPath('status', ReportVideo::READY);
        $this->actingAs($this->a['admin'])->getJson(route('reports.video.status', $report))->assertOk();
        $this->actingAs($this->b['admin'])->getJson(route('reports.video.status', $report))->assertNotFound();
    }

    public function test_ffmpeg_compresses_to_720p_h264_and_removes_the_original_and_temporaries(): void
    {
        $this->ffmpeg();

        $this->send($this->fixture('iphone.mov'))->assertSessionHasNoErrors(); // 1080p MOV
        $video = ReportVideo::withoutGlobalScopes()->sole();
        $original = $video->path;
        $this->assertSame([ReportVideo::PENDING, 'video/quicktime', 2], [$video->status, $video->mime, $video->duration]);

        $this->artisan('media:process-videos')->assertSuccessful();

        $video->refresh();
        $this->assertSame([ReportVideo::READY, 'video/mp4'], [$video->status, $video->mime]);
        Storage::disk('local')->assertMissing($original);
        $this->assertSame([$video->path], Storage::disk('local')->allFiles()); // sin temporales ni originales

        $probe = json_decode((string) shell_exec(escapeshellarg(config('media.ffprobe')).' -v error -select_streams v:0 -show_entries stream=codec_name,height -of json '.escapeshellarg($video->fullPath())), true);
        $this->assertSame(['h264', 720], [$probe['streams'][0]['codec_name'], $probe['streams'][0]['height']]);

        // Se sirve (y con rangos) solo con permiso.
        $report = Report::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['admin'])->get(route('reports.video', $report))->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $this->actingAs($this->b['admin'])->get(route('reports.video', $report))->assertNotFound();

        // Procesar dos veces no hace nada (ya no está pendiente).
        $path = $video->path;
        app(ReportVideoService::class)->process($video->fresh());
        $this->assertSame($path, $video->fresh()->path);
    }

    public function test_a_video_ffmpeg_cannot_decode_fails_is_deleted_and_can_be_replaced(): void
    {
        $this->ffmpeg();

        // Encabezado válido (duración legible) pero sin pistas de video: FFmpeg no lo puede procesar.
        $mvhd = "\x00\x00\x00\x00".str_repeat("\x00", 8).pack('N2', 1000, 5000).str_repeat("\x00", 80);
        $bytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".pack('N', 16 + strlen($mvhd)).'moov'.pack('N', 8 + strlen($mvhd)).'mvhd'.$mvhd.pack('N', 8 + 4096).'mdat'.random_bytes(4096);
        config(['media.ffprobe' => '']); // la duración se lee del encabezado
        $this->send(UploadedFile::fake()->createWithContent('raro.mp4', $bytes))->assertSessionHasNoErrors();
        $this->ffmpeg();

        $this->artisan('media:process-videos')->assertSuccessful();

        $video = ReportVideo::withoutGlobalScopes()->sole();
        $this->assertSame(ReportVideo::FAILED, $video->status);
        $this->assertStringContainsString('No se pudo procesar el video', $video->processing_note);
        $this->assertSame([], Storage::disk('local')->allFiles()); // ni el original ni temporales

        $report = Report::withoutGlobalScopes()->sole();
        $this->actingAs($this->a['technician'])->get(route('reports.video', $report))->assertNotFound();
        $this->getJson(route('reports.video.status', $report))->assertJsonPath('status', ReportVideo::FAILED);
        $this->get(route('reports.show', ['company' => $this->a['company']->slug, 'report' => $report]))->assertSee('No se pudo procesar el video');

        // Se puede volver a subir.
        $this->post(route('reports.video.store', $report), ['video' => $this->fixture('corto.mp4')])->assertSessionHasNoErrors();
        $this->assertSame(ReportVideo::PENDING, ReportVideo::withoutGlobalScopes()->sole()->status);
    }

    public function test_a_header_that_lies_about_the_duration_is_caught_when_compressing(): void
    {
        $this->ffmpeg();

        // 95 s reales con la caja mvhd editada para declarar 10 s.
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/videos/largo.mp4'));
        $at = strpos($bytes, 'mvhd') + 4;
        $version = ord($bytes[$at]);
        $scaleAt = $at + ($version === 1 ? 20 : 12);
        $scale = unpack('N', substr($bytes, $scaleAt, 4))[1];
        $bytes = $version === 1
            ? substr_replace($bytes, pack('N2', 0, 10 * $scale), $scaleAt + 4, 8)
            : substr_replace($bytes, pack('N', 10 * $scale), $scaleAt + 4, 4);

        config(['media.ffprobe' => '']); // sin ffprobe, la carga solo puede leer el encabezado
        $this->send(UploadedFile::fake()->createWithContent('trucho.mp4', $bytes))->assertSessionHasNoErrors();
        $this->assertSame(10, ReportVideo::withoutGlobalScopes()->sole()->duration);
        $this->ffmpeg();

        $this->artisan('media:process-videos')->assertSuccessful();

        $video = ReportVideo::withoutGlobalScopes()->sole();
        $this->assertSame(ReportVideo::FAILED, $video->status);
        $this->assertStringContainsString('dura más de 90 segundos', $video->processing_note);
        $this->assertSame([], Storage::disk('local')->allFiles()); // ni el original ni temporales
    }
}
