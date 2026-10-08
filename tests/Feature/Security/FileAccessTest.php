<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Una empresa nunca puede descargar archivos de otra, aunque conozca el
 * ID, el nombre del archivo, el path o la URL.
 */
class FileAccessTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Report $reportB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();

        $this->reportB = $this->reportWithPhoto($this->b);
    }

    private function reportWithPhoto(array $tenant, string $disk = 'local'): Report
    {
        $path = 'reports/'.$tenant['company']->id.'/'.str_repeat($disk === 'local' ? 'a' : 'p', 40).'.jpg';

        // Foto vieja (columna reports.photo, ya copiada a report_photos por la migración).
        return Report::factory()->withPhoto($disk, $path, legacyColumn: true)->create([
            'building_id' => $tenant['building']->id,
            'user_id' => $tenant['technician']->id,
        ]);
    }

    public function test_owner_and_company_admin_can_download_photo(): void
    {
        $this->actingAs($this->b['technician'])->get(route('reports.photo', $this->reportB))->assertOk();
        $this->actingAs($this->b['admin'])->get(route('reports.photo', $this->reportB))->assertOk();
    }

    public function test_other_company_cannot_download_photo_by_id(): void
    {
        $this->actingAs($this->a['admin'])->get(route('reports.photo', $this->reportB))->assertNotFound();
        $this->actingAs($this->a['technician'])->get(route('reports.photo', $this->reportB))->assertNotFound();
    }

    public function test_other_technician_of_same_company_cannot_download_photo(): void
    {
        $colleague = User::factory()->technician()->create(['company_id' => $this->b['company']->id]);

        $this->actingAs($colleague)->get(route('reports.photo', $this->reportB))->assertNotFound();
    }

    public function test_guest_cannot_download_photo(): void
    {
        $this->get(route('reports.photo', $this->reportB))->assertRedirect('/login');
    }

    public function test_new_photos_are_not_reachable_by_public_url(): void
    {
        // El archivo existe solo en el disco privado: /storage/... no lo sirve.
        Storage::disk('local')->assertExists($this->reportB->photo);
        Storage::disk('public')->assertMissing($this->reportB->photo);

        $response = $this->get('/storage/'.$this->reportB->photo);
        $this->assertContains($response->status(), [403, 404], 'El archivo no debe servirse sin pasar por la ruta protegida.');
    }

    public function test_super_admin_only_inside_the_selected_company(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)->get(route('reports.photo', $this->reportB))->assertNotFound();

        $this->actingAs($superAdmin)
            ->withSession(['selected_company_id' => $this->b['company']->id])
            ->get(route('reports.photo', $this->reportB))
            ->assertOk();
    }

    public function test_tampered_paths_are_never_served(): void
    {
        Storage::disk('local')->put('reports/'.$this->a['company']->id.'/secreto.jpg', 'secreto de A');
        Storage::disk('local')->put('.env', 'APP_KEY=x');

        foreach (['reports/'.$this->a['company']->id.'/secreto.jpg', '../../.env', 'reports/'.$this->b['company']->id.'/../../.env'] as $path) {
            ReportPhoto::withoutGlobalScopes()->where('report_id', $this->reportB->id)->update(['path' => $path]);

            $this->actingAs($this->b['admin'])
                ->get(route('reports.photo', $this->reportB))
                ->assertNotFound();
        }

        $this->actingAs($this->b['admin'])->get('/files/reports/..%2F..%2F.env/photo')->assertNotFound();
    }

    public function test_legacy_public_photos_are_served_through_the_route_and_can_be_migrated(): void
    {
        $legacy = $this->reportWithPhoto($this->a, 'public');

        $this->actingAs($this->a['admin'])->get(route('reports.photo', $legacy))->assertOk();

        $this->artisan('reports:move-photos-private', ['--dry-run' => true])->assertSuccessful();
        Storage::disk('public')->assertExists($legacy->photo);

        $this->artisan('reports:move-photos-private')->assertSuccessful();

        Storage::disk('public')->assertMissing($legacy->photo);
        Storage::disk('local')->assertExists($legacy->photo);
        $this->actingAs($this->a['admin'])->get(route('reports.photo', $legacy))->assertOk();
    }

    public function test_report_views_use_the_protected_route(): void
    {
        $this->actingAs($this->b['technician'])
            ->get("/{$this->b['company']->slug}/reports/{$this->reportB->id}")
            ->assertOk()
            ->assertSee($this->reportB->photos()->first()->url(), false)
            ->assertDontSee('/storage/reports', false);

        $this->actingInPanel($this->b['admin'])
            ->get(ReportResource::getUrl('view', ['record' => $this->reportB]))
            ->assertOk()
            ->assertDontSee('/storage/reports', false);
    }

    public function test_delivery_note_documents_are_isolated(): void
    {
        $noteB = DeliveryNote::factory()->create([
            'building_id' => $this->b['building']->id,
            'user_id' => $this->b['technician']->id,
            'signature' => $this->validSignature(),
            'number' => '00000555',
        ]);

        // Ni por número (show/pdf) ni por token con el slug de otra empresa.
        $this->actingAs($this->a['admin'])->get("/{$this->a['company']->slug}/delivery-notes/{$noteB->number}/pdf")->assertNotFound();
        $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/delivery-notes/{$noteB->number}")->assertNotFound();
        $this->get("/{$this->a['company']->slug}/public/delivery-notes/{$noteB->public_token}")->assertNotFound();
        $this->get("/{$this->a['company']->slug}/public/delivery-notes/../{$noteB->public_token}")->assertNotFound();
    }
}
