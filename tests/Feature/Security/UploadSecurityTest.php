<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Reports\Pages\CreateReport;
use App\Models\Report;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private function reportPayload(array $tenant, UploadedFile $photo): array
    {
        return [
            'building_id' => $tenant['building']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => 'Ruido en la cabina',
            'priority' => 'alta',
            'photos' => [$photo],
        ];
    }

    public static function dangerousFiles(): array
    {
        return [
            'svg con script' => ['evil.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'php disfrazado' => ['shell.php', 'application/x-php', '<?php system($_GET["c"]); ?>'],
            'php con extensión de imagen' => ['shell.jpg', 'application/x-php', '<?php system($_GET["c"]); ?>'],
            'html' => ['page.html', 'text/html', '<script>alert(1)</script>'],
            'pdf' => ['doc.pdf', 'application/pdf', '%PDF-1.4 fake'],
        ];
    }

    #[DataProvider('dangerousFiles')]
    public function test_report_photo_rejects_non_image_files(string $name, string $mime, string $content): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $a = $this->makeTenant();

        $file = UploadedFile::fake()->createWithContent($name, $content)->mimeType($mime);

        $this->actingAs($a['technician'])
            ->post("/{$a['company']->slug}/reports", $this->reportPayload($a, $file))
            ->assertSessionHasErrors('photos.0');

        $this->assertSame(0, Report::withoutGlobalScopes()->count());
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_report_photo_is_reencoded_with_random_name_inside_company_folder(): void
    {
        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Requiere Imagick o GD (procesa la foto antes de guardar).');
        }

        Storage::fake('local');
        Storage::fake('public');
        $a = $this->makeTenant();

        $this->actingAs($a['technician'])
            ->post("/{$a['company']->slug}/reports", $this->reportPayload($a, UploadedFile::fake()->image('../../../etc/passwd.jpg')))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $path = Report::withoutGlobalScopes()->sole()->photos()->withoutGlobalScopes()->sole()->path;

        $this->assertMatchesRegularExpression('#^reports/'.$a['company']->id.'/[A-Za-z0-9]{40}\.jpg$#', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_filament_report_upload_rejects_svg(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $a = $this->makeTenant();

        $this->actingInPanel($a['admin']);

        Livewire::test(CreateReport::class)
            ->fillForm([
                'building_id' => $a['building']->id,
                'elevator_number' => 'Ascensor 1',
                'description' => 'Con svg',
                'priority' => 'baja',
                'status' => 'pendiente',
                'new_photos' => [UploadedFile::fake()->createWithContent(
                    'evil.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
                )->mimeType('image/svg+xml')],
            ])
            ->call('create')
            ->assertHasFormErrors(['new_photos']);

        $this->assertSame(0, Report::withoutGlobalScopes()->count());
    }

    public function test_filament_report_upload_stores_images_on_private_disk(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        $a = $this->makeTenant();

        $this->actingInPanel($a['admin']);

        Livewire::test(CreateReport::class)
            ->fillForm([
                'building_id' => $a['building']->id,
                'elevator_number' => 'Ascensor 1',
                'description' => 'Con foto',
                'priority' => 'baja',
                'status' => 'pendiente',
                'new_photos' => [UploadedFile::fake()->image('ok.jpg', 800, 600), UploadedFile::fake()->image('ok2.png', 600, 900)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $report = Report::withoutGlobalScopes()->sole();
        $photos = $report->photos()->withoutGlobalScopes()->get();

        $this->assertSame($a['company']->id, $report->company_id);
        $this->assertCount(2, $photos);

        foreach ($photos as $photo) {
            $this->assertSame($a['company']->id, $photo->company_id);
            $this->assertStringEndsWith('.jpg', $photo->path); // re-codificada, también desde el panel
            Storage::disk('local')->assertExists($photo->path);
            Storage::disk('public')->assertMissing($photo->path);
        }
    }
}
