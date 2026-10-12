<?php

namespace Tests\Feature\Visits;

use App\Enums\PlanLimit;
use App\Filament\Resources\Inspections\InspectionResource;
use App\Models\BuildingVisit;
use App\Models\Company;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Services\Insights\AttentionCenter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Reporte de inspección: «Crear reporte» / «Ver reporte» desde Visitas. El
 * inspector registra observaciones y fotos (reutiliza los reportes) sin que
 * la inspección se trate como una reparación: sin prioridad ni estado, sin
 * cupo del plan, sin alertas de falla y fuera del portal. Empresa, visita y
 * asignación se verifican en el servidor.
 */
class InspectionReportTest extends TestCase
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
        $this->a['building']->update(['elevator_count' => 1]);
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);
    }

    private function url(string $path, ?array $tenant = null): string
    {
        return '/'.($tenant ?? $this->a)['company']->slug.'/'.$path;
    }

    private function startFromCard(?User $user = null)
    {
        return $this->actingAs($user ?? $this->a['technician'])->post($this->url('visits/start'), [
            'building_id' => $this->a['building']->id, 'assignment_type' => 'inspection', 'month' => 10, 'year' => 2026, 'then' => 'report',
        ]);
    }

    private function inspection(): BuildingVisit
    {
        $this->startFromCard()->assertRedirect();

        return BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'inspection')->sole();
    }

    private function save(BuildingVisit $visit, array $data = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->a['technician'])->post($this->url("visits/{$visit->id}/report"), [
            'observations' => 'Paracaídas probado, sin novedades.', ...$data,
        ]);
    }

    public function test_the_inspector_creates_and_views_the_report_from_visitas(): void
    {
        $this->a['company']->forceFill(['photos_inspection' => true])->save();

        // En la tarjeta: «Crear reporte» abre la inspección en curso y va a su reporte.
        $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()->assertSee('Crear reporte');
        $visit = $this->inspection();
        $this->startFromCard()->assertRedirect(route('inspection-reports.show', ['company' => $this->a['company']->slug, 'visit' => $visit->id]));
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}/report"))->assertOk()
            ->assertSee('data-inspection-report-form', false)->assertSee('Crear reporte')
            ->assertSee('aria-label="Tomar foto"', false)->assertSee('capture="environment"', false)->assertSee('aria-label="Elegir de la galería"', false)
            ->assertDontSee('Prioridad');

        $this->save($visit, ['photos' => [UploadedFile::fake()->image('a.jpg', 800, 600), UploadedFile::fake()->image('b.jpg', 800, 600)]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $report = Report::withoutGlobalScopes()->sole();
        $this->assertSame([Report::KIND_INSPECTION, $visit->id, $this->a['technician']->id, 2], [$report->kind, $report->building_visit_id, $report->user_id, $report->photos()->count()]);
        foreach ($report->photos as $photo) {
            $this->assertStringStartsWith('reports/'.$this->a['company']->id.'/', $photo->path);
            Storage::disk('local')->assertExists($photo->path);
        }

        // «Ver reporte» en la tarjeta y en la pantalla; editar no crea otro.
        $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()->assertSee('data-inspection-report-link="view"', false)->assertSee('Ver reporte');
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}/report"))->assertOk()
            ->assertSee('Paracaídas probado')->assertSee($report->photos->first()->thumbUrl(), false);
        $this->save($visit, ['observations' => 'Paracaídas y limitador probados.'])->assertSessionHasNoErrors();
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        $this->assertSame('Paracaídas y limitador probados.', $report->fresh()->description);

        // El remito completa la inspección; el reporte sigue en su visita y el admin lo ve en la inspección.
        $this->actingAs($this->a['technician'])->post($this->url('delivery-notes/store'), [
            'building_id' => $this->a['building']->id, 'description' => 'Inspección.', 'month' => 10, 'year' => 2026,
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'assignment_type' => 'inspection', 'performed' => '1',
            'signature_name' => 'Inspector', 'signature' => $this->validSignature(),
        ])->assertSessionHasNoErrors();
        $note = DeliveryNote::sole();
        $this->assertSame($visit->id, $note->building_visit_id);
        $this->actingAs($this->a['technician'])->get($this->url('buildings'))->assertOk()->assertSee('Inspección realizada')->assertSee('Ver reporte');
        $this->actingInPanel($this->a['admin']);
        $this->get(InspectionResource::getUrl('view', ['record' => $note]))->assertOk()
            ->assertSee('Paracaídas y limitador probados.')->assertSee($report->photos->first()->thumbUrl(), false);
        $this->get(route('reports.pdf', $report))->assertOk();
    }

    public function test_an_inspection_report_is_not_a_repair(): void
    {
        $visit = $this->inspection();
        $this->save($visit, ['observations' => 'Freno con desgaste: recomendar cambio.'])->assertSessionHasNoErrors();
        $report = Report::withoutGlobalScopes()->sole();

        // No consume el cupo de reportes, no es una alerta y no figura en «Mis reportes» ni en fallas.
        $this->assertSame(0, PlanLimit::ReportsPerMonth->usage(Company::find($this->a['company']->id)));
        $this->assertNull(app(AttentionCenter::class)->basic($this->a['company'])->firstWhere('title', 'Reportes de prioridad alta o crítica sin revisar'));
        $this->actingAs($this->a['technician'])->get($this->url('reports'))->assertOk()->assertDontSee('Freno con desgaste');
        $this->assertSame(0, Report::incidents()->withoutGlobalScopes()->count());

        // Ni el tipo ni la visita se pueden asignar desde un reporte común.
        $this->assertNotContains('kind', (new Report)->getFillable());
        $this->assertNotContains('building_visit_id', (new Report)->getFillable());

        // Nunca en el portal, aunque se marque como compartido.
        $report->forceFill(['shared_with_client' => true])->saveQuietly();
        $client = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => User::ROLE_CLIENT]);
        $this->actingAs($client)->get(route('portal.report', ['company' => $this->a['company']->slug, 'report' => $report->id]))->assertNotFound();
    }

    public function test_photos_follow_the_company_switch_and_are_kept_when_it_is_turned_off(): void
    {
        $visit = $this->inspection();

        // Apagadas (por defecto): el reporte se hace igual, solo con observaciones.
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}/report"))->assertOk()
            ->assertSee('data-report-photos-disabled', false)->assertDontSee('aria-label="Tomar foto"', false);
        $this->save($visit, ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSessionHasErrors('photos');
        $this->assertSame(0, Report::withoutGlobalScopes()->count());
        $this->save($visit)->assertSessionHasNoErrors();

        // Encendidas: agrega fotos. Al apagarlas, se conservan y no se pueden quitar.
        $this->a['company']->forceFill(['photos_inspection' => true])->save();
        $this->save($visit, ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSessionHasNoErrors();
        $photo = ReportPhoto::withoutGlobalScopes()->sole();
        $this->a['company']->forceFill(['photos_inspection' => false])->save();
        $this->actingAs($this->a['technician'])->delete($this->url("visits/{$visit->id}/report/photos/{$photo->id}"))->assertSessionHasErrors('photos');
        Storage::disk('local')->assertExists($photo->path);
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}/report"))->assertOk()->assertSee($photo->thumbUrl(), false);

        // Encendidas otra vez: quien lo hizo la puede quitar (y se borra el archivo).
        $this->a['company']->forceFill(['photos_inspection' => true])->save();
        $this->actingAs($this->a['technician'])->delete($this->url("visits/{$visit->id}/report/photos/{$photo->id}"))->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($photo->path);

        // Más de 6 fotos: rechazado sin dejar archivos.
        $before = count(Storage::disk('local')->allFiles());
        $this->save($visit, ['photos' => array_map(fn ($i) => UploadedFile::fake()->image("{$i}.jpg"), range(1, 7))])->assertSessionHasErrors('photos');
        $this->assertSame($before, count(Storage::disk('local')->allFiles()));
        // Un archivo que no es imagen: rechazado.
        $this->save($visit, ['photos' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])->assertSessionHasErrors('photos.0');
    }

    public function test_ids_from_another_company_or_job_and_unassigned_users_are_rejected(): void
    {
        $this->a['company']->forceFill(['photos_inspection' => true])->save();
        $visit = $this->inspection();
        $this->save($visit, ['photos' => [UploadedFile::fake()->image('a.jpg')]])->assertSessionHasNoErrors();
        $report = Report::withoutGlobalScopes()->sole();
        $photo = $report->photos()->first();

        // Una visita de mantenimiento no tiene reporte de inspección.
        $this->a['company']->forceFill(['photos_maintenance' => true])->save();
        $this->actingAs($this->a['technician'])->post($this->url('visits/start'), ['building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => 10, 'year' => 2026]);
        $maintenance = BuildingVisit::withoutGlobalScope('completed')->where('assignment_type', 'maintenance')->sole();
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$maintenance->id}/report"))->assertNotFound();
        $this->save($maintenance)->assertNotFound();

        // Técnico de A sin asignación: ni ve ni crea.
        $outsider = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->actingAs($outsider)->get($this->url("visits/{$visit->id}/report"))->assertNotFound();
        $this->save($visit, [], $outsider)->assertNotFound();
        $this->actingAs($outsider)->get($photo->url())->assertNotFound();
        $this->startFromCard($outsider)->assertForbidden();

        // Otro inspector asignado: lo ve, pero no lo pisa.
        $coworker = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($coworker->id, ['type' => 'inspection']);
        $this->actingAs($coworker)->get($this->url("visits/{$visit->id}/report"))->assertOk()->assertDontSee('data-edit-report', false);
        $this->actingAs($coworker)->get($photo->url())->assertOk();
        $this->save($visit, ['observations' => 'Pisado por otro.'], $coworker)->assertSessionHasErrors('observations');
        $this->actingAs($coworker)->delete($this->url("visits/{$visit->id}/report/photos/{$photo->id}"))->assertNotFound();
        $this->assertSame('Paracaídas probado, sin novedades.', $report->fresh()->description);

        // Otra empresa: con los ids de A, en su URL o en la de A.
        auth()->logout();
        $b = $this->makeTenant();
        $b['building']->users()->attach($b['technician']->id, ['type' => 'inspection']);
        $this->actingAs($b['technician'])->get($this->url("visits/{$visit->id}/report", $b))->assertNotFound();
        $this->actingAs($b['technician'])->post($this->url("visits/{$visit->id}/report", $b), ['observations' => 'Ajeno total.'])->assertNotFound();
        $this->actingAs($b['technician'])->post($this->url("visits/{$visit->id}/report"), ['observations' => 'Ajeno total.'])->assertForbidden();
        $this->actingAs($b['admin'])->get($photo->url())->assertNotFound();
        $this->actingAs($b['admin'])->get(route('reports.pdf', $report))->assertNotFound();
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
        // El archivo nunca es público.
        $this->get('/storage/'.$photo->path)->assertNotFound();
    }

    public function test_an_inspection_with_a_report_is_not_discarded(): void
    {
        $visit = $this->inspection();
        $this->save($visit)->assertSessionHasNoErrors();

        $this->actingAs($this->a['technician'])->delete($this->url("visits/{$visit->id}"))->assertSessionHasErrors('general');
        $this->assertTrue(BuildingVisit::withoutGlobalScope('completed')->whereKey($visit->id)->exists());
        $this->assertSame(1, Report::withoutGlobalScopes()->count());
    }
}
