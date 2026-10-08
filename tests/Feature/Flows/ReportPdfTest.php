<?php

namespace Tests\Feature\Flows;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Client;
use App\Models\Report;
use App\Models\User;
use App\Services\Reports\ReportPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * PDF de reportes generado en el servidor: contenido, fotos, muchas páginas,
 * caracteres especiales y permisos.
 */
class ReportPdfTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Requiere Imagick o GD (procesa la foto antes de guardar).');
        }

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->makeTenant();
    }

    private function report(array $photos = [], array $attributes = []): Report
    {
        $report = Report::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
            ...$attributes,
        ]);

        app(ReportPhotoService::class)->add($report, $photos);

        return $report;
    }

    private function pdf(Report $report, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a['admin'])->get(route('reports.pdf', $report));
    }

    private function pages(string $pdf): int
    {
        return preg_match_all('#/Type\s*/Page[^s]#', $pdf);
    }

    public function test_the_pdf_has_all_the_report_data_and_escapes_special_characters(): void
    {
        $this->a['company']->update(['name' => 'Ascensores Ñandú & Cía', 'cuit' => '30-71234567-8']);
        Client::whereKey($this->a['building']->client_id)->update(['name' => 'Consorcio Güemes "Torre"']);
        $report = $this->report([], [
            'description' => "Ruido al frenar.\nLínea 2: <script>alert('x')</script> 100% ½ € °C",
            'observations' => 'Se pidió el repuesto: contactor 3×25 A.',
            'priority' => 'critica',
        ]);
        $report->load(['company', 'building.client', 'user', 'photos']);

        $html = view('reports.pdf', ['report' => $report, 'photos' => collect(), 'logo' => null])->render();

        foreach ([
            'Ascensores Ñandú &amp; Cía', '30-71234567-8', 'Consorcio Güemes &quot;Torre&quot;',
            e($this->a['building']->name), 'Ascensor 1', e($this->a['technician']->name),
            $report->created_at->format('d/m/Y'), 'Crítica', 'Reporte técnico #'.$report->id,
            'Línea 2: &lt;script&gt;', '½ € °C', 'contactor 3×25 A', 'El reporte no tiene fotos.',
        ] as $expected) {
            $this->assertStringContainsString($expected, $html);
        }

        $this->assertStringNotContainsString("<script>alert('x')</script>", $html);

        $response = $this->pdf($report)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertStringContainsString('reporte-'.$report->id.'-', $response->headers->get('Content-Disposition'));
    }

    public function test_photos_are_embedded_without_urls_or_paths_and_fit_the_page(): void
    {
        $report = $this->report([
            UploadedFile::fake()->image('vertical.jpg', 1200, 1600),
            UploadedFile::fake()->image('horizontal.jpg', 1600, 1200),
            UploadedFile::fake()->image('panoramica.png', 4000, 1000),
            UploadedFile::fake()->image('alta.jpg', 600, 3000),
            UploadedFile::fake()->image('cuadrada.webp', 1000, 1000),
            UploadedFile::fake()->image('chica.jpg', 200, 150),
        ]);

        $started = microtime(true);
        $pdf = $this->pdf($report)->assertOk()->getContent();
        $seconds = microtime(true) - $started;

        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertSame(6, preg_match_all('#/Subtype\s*/Image#', $pdf), 'Las 6 fotos incrustadas');
        $this->assertGreaterThanOrEqual(2, $this->pages($pdf));
        $this->assertLessThan(10, $seconds, 'Generar un PDF con 6 fotos no debería tardar tanto');

        foreach ($report->photos as $photo) {
            $this->assertStringNotContainsString($photo->path, $pdf);
        }
        $this->assertStringNotContainsString('/files/reports', $pdf);

        // Cada foto entra en su caja (84 × 105 mm) sin deformarse.
        $html = view('reports.pdf', [
            'report' => $report->load(['company', 'building.client', 'user', 'photos']),
            'photos' => $report->photos->map(fn ($p) => ['src' => 'x', 'width' => min(84, $p->width), 'height' => 1]),
            'logo' => null,
        ])->render();
        $this->assertSame(6, substr_count($html, 'class="photo-caption"'));
    }

    public function test_long_reports_span_several_pages(): void
    {
        $long = trim(str_repeat('El ascensor presenta vibraciones al pasar por el piso 7; se revisaron guías y zapatas. ', 55));
        $report = $this->report([], ['description' => mb_substr($long, 0, 5000), 'observations' => mb_substr($long, 0, 5000)]);

        $pdf = $this->pdf($report)->assertOk()->getContent();

        $this->assertGreaterThanOrEqual(3, $this->pages($pdf));
    }

    public function test_only_who_can_see_the_report_gets_its_pdf(): void
    {
        $report = $this->report([UploadedFile::fake()->image('a.jpg', 400, 300)]);
        $colleague = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        auth()->logout();
        $b = $this->makeTenant();

        $this->pdf($report, $this->a['admin'])->assertOk();
        $this->pdf($report, $this->a['technician'])->assertOk();

        $this->pdf($report, $colleague)->assertNotFound();
        $this->pdf($report, $b['admin'])->assertNotFound();
        $this->pdf($report, $b['technician'])->assertNotFound();

        auth()->logout(); // logueado, el usuario nuevo tomaría la empresa del actual
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin)->get(route('reports.pdf', $report))->assertNotFound();
        $this->actingAs($superAdmin)->withSession(['selected_company_id' => $this->a['company']->id])->get(route('reports.pdf', $report))->assertOk();

        auth()->logout();
        $this->get(route('reports.pdf', $report))->assertRedirect('/login');
        $this->get('/files/reports/999999/pdf')->assertRedirect('/login');
    }

    public function test_the_technician_and_the_admin_have_a_pdf_button(): void
    {
        $report = $this->report();

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/reports/{$report->id}")
            ->assertOk()
            ->assertSee(route('reports.pdf', $report), false);

        $this->actingInPanel($this->a['admin'])
            ->get(ReportResource::getUrl('view', ['record' => $report]))
            ->assertOk()
            ->assertSee(route('reports.pdf', $report), false);
    }

    public function test_the_technician_app_only_accepts_equipment_of_that_building(): void
    {
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id,
            'elevator_number' => 'Ascensor 99',
            'description' => 'Equipo inexistente.',
            'priority' => 'baja',
        ])->assertSessionHasErrors('elevator_number');

        $this->assertSame(0, Report::count());
    }
}
