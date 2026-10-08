<?php

namespace Tests\Feature\Flows;

use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reportes del técnico con fotos reales de distintos tamaños, orientaciones
 * y formatos, y textos largos.
 */
class ReportPhotosTest extends TestCase
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
        $this->a = $this->makeTenant();
    }

    private function send(UploadedFile $photo, string $description = 'La puerta de cabina no cierra bien.')
    {
        return $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => $description,
            'priority' => 'alta',
            'photo' => $photo,
        ]);
    }

    /** @return array{0:int,1:int} ancho y alto de la foto guardada */
    private function storedSize(Report $report): array
    {
        $size = getimagesize(Storage::disk('local')->path($report->photo));

        return [$size[0], $size[1]];
    }

    public function test_photos_of_every_shape_and_size_are_stored_light_and_upright(): void
    {
        $cases = [
            'vertical.jpg' => [[1200, 1600], [1200, 1600]],
            'horizontal.jpg' => [[1600, 1200], [1600, 1200]],
            'enorme.jpg' => [[6000, 4000], [2000, 1333]],   // se achica sin deformar
            'enorme-vertical.png' => [[3000, 5000], [1200, 2000]],
            'chica.png' => [[320, 240], [320, 240]],       // nunca se agranda
            'formato.webp' => [[800, 600], [800, 600]],
        ];

        foreach ($cases as $name => [[$w, $h], $expected]) {
            $this->send(UploadedFile::fake()->image($name, $w, $h))->assertSessionHasNoErrors();

            $report = Report::latest('id')->first();
            $this->assertSame($expected, $this->storedSize($report), $name);
            $this->assertStringStartsWith("reports/{$this->a['company']->id}/", $report->photo);
            $this->assertStringEndsWith('.jpg', $report->photo); // siempre re-codificada
            $this->assertSame('image/jpeg', mime_content_type(Storage::disk('local')->path($report->photo)));
        }

        $this->assertSame(count($cases), Report::count());

        // El técnico la ve; se sirve por el controlador con permisos.
        $this->actingAs($this->a['technician'])->get(route('reports.photo', $report))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_long_and_short_texts(): void
    {
        $long = trim(str_repeat('El ascensor hace un ruido metálico al frenar en el piso 4. ', 80)); // ~4.700 caracteres

        $this->send(UploadedFile::fake()->image('a.jpg', 800, 600), $long)->assertSessionHasNoErrors();
        $report = Report::sole();
        $this->assertSame($long, $report->description);
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/reports/{$report->id}")->assertOk()->assertSee('ruido metálico');

        // Muy corto o demasiado largo: error claro, sin crear nada.
        $this->send(UploadedFile::fake()->image('b.jpg', 800, 600), 'mal')->assertSessionHasErrors('description');
        $this->send(UploadedFile::fake()->image('c.jpg', 800, 600), str_repeat('x', 5001))->assertSessionHasErrors('description');
        $this->assertSame(1, Report::count());
    }

    public function test_broken_or_missing_photos_never_create_a_report(): void
    {
        // Archivo con extensión de imagen pero contenido roto.
        $this->send(UploadedFile::fake()->createWithContent('rota.jpg', str_repeat("\xFF\xD8\xFF\x00basura", 50)))
            ->assertSessionHasErrors('photo');

        // Sin foto (hoy es obligatoria).
        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1',
            'description' => 'Sin foto adjunta.', 'priority' => 'baja',
        ])->assertSessionHasErrors('photo');

        // Más de 10 MB.
        $this->send(UploadedFile::fake()->image('pesada.jpg', 100, 100)->size(11000))->assertSessionHasErrors('photo');

        $this->assertSame(0, Report::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
