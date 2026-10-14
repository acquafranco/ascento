<?php

namespace Tests\Feature\Visits;

use App\Filament\Pages\Agenda;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\PhotoSection;
use App\Models\User;
use App\Models\VisitPhoto;
use App\Services\Insights\Indicators;
use App\Services\Insights\MaintenanceAgenda;
use App\Services\Visits\PhotoTarget;
use App\Services\Visits\VisitPhotoService;
use App\Services\Visits\VisitPlanService;
use App\Services\Visits\VisitSchedule;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Auditoría final de la Etapa 5: visitas en curso abandonadas, permisos de
 * fotos con el tipo desactivado, carreras entre subir y descartar, la Agenda
 * y los indicadores del admin contando visitas como el técnico, la firma
 * rechazada que no vuelve al formulario y la numeración de equipos.
 */
class StageFiveAuditTest extends TestCase
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

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->usingAscentoSince($this->a, '2025-01-01'); // ya usaba Ascento en los meses que se consultan
        $this->a['building']->update(['elevator_count' => 2]);
        $this->a['company']->forceFill(['photos_maintenance' => true])->save();
        // Otro técnico asignado al mismo edificio para mantenimiento.
        $this->other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->a['building']->users()->attach($this->other->id, ['type' => 'maintenance']);
    }

    private function url(string $path): string
    {
        return "/{$this->a['company']->slug}/{$path}";
    }

    private function start(User $as, int $month = 10, int $year = 2026)
    {
        return $this->actingAs($as)->post($this->url('visits/start'), [
            'building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'month' => $month, 'year' => $year,
        ]);
    }

    private function openVisit(): BuildingVisit
    {
        return BuildingVisit::withoutGlobalScope('completed')->where('in_progress', true)->latest('id')->firstOrFail();
    }

    private function section(): PhotoSection
    {
        // Pozo: zona del sistema para mantenimientos y órdenes de trabajo.
        return PhotoSection::zonesFor($this->a['company']->id, 'maintenance')->firstWhere('code', 'pozo');
    }

    private function upload(int $visitId, User $as, string $stage = 'before')
    {
        return $this->actingAs($as)->postJson($this->url('visit-photos'), [
            'visit_id' => $visitId, 'photo_section_id' => $this->section()->id, 'stage' => $stage,
            'photo' => UploadedFile::fake()->image('foto.jpg', 640, 480),
        ]);
    }

    private function sign(User $as, int $month = 10, array $overrides = [])
    {
        return $this->actingAs($as)->post($this->url('delivery-notes/store'), [
            'building_id' => $this->a['building']->id, 'description' => 'Mantenimiento.',
            'month' => $month, 'year' => 2026, 'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'assignment_type' => 'maintenance', 'performed' => '1', 'signature_name' => 'Técnico',
            'signature' => $this->validSignature(), ...$overrides,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | VISITAS EN CURSO ABANDONADAS
    |--------------------------------------------------------------------------
    */

    public function test_an_abandoned_visit_never_blocks_the_period_and_the_next_remito_completes_it(): void
    {
        // Trimestral agosto–octubre: el técnico la empieza en agosto y la abandona.
        $this->travelTo(Carbon::parse('2026-08-02 09:00'));
        app(VisitPlanService::class)->set($this->a['building'], 'maintenance', 1, 3, CarbonImmutable::parse('2026-08-01'));
        $this->travelTo(Carbon::parse('2026-08-10 09:00'));
        $this->start($this->a['technician'], 8)->assertRedirect();
        $visit = $this->openVisit();
        $this->upload($visit->id, $this->a['technician'])->assertCreated();

        // Septiembre: sigue pendiente (no "hecho en el período") y ocupa un único lugar.
        $this->travelTo(Carbon::parse('2026-09-15 09:00'));
        $eval = VisitSchedule::load([$this->a['building']->id], 9, 2026)->evaluate($this->a['building']->id, 'maintenance', 9, 2026);
        $this->assertSame([1, 0, 1, false], [$eval['expected'], $eval['done'], $eval['pending'], $eval['covered']]);

        // Otro técnico asignado hace el remito de septiembre: completa ESA visita, no crea otra.
        $this->sign($this->other, 9)->assertSessionHasNoErrors();
        $this->assertSame(1, BuildingVisit::withoutGlobalScope('completed')->count());
        $done = BuildingVisit::sole();
        $this->assertSame([$visit->id, 9, $visit->period_key, false], [$done->id, (int) $done->month, $done->period_key, $done->in_progress]);
        $this->assertSame(DeliveryNote::sole()->id, VisitPhoto::withoutGlobalScopes()->sole()->delivery_note_id);

        // El período siguiente (noviembre–enero) no se ve afectado.
        $next = VisitSchedule::load([$this->a['building']->id], 11, 2026)->evaluate($this->a['building']->id, 'maintenance', 11, 2026);
        $this->assertSame([1, 0, 1], [$next['expected'], $next['done'], $next['pending']]);
    }

    public function test_a_visit_abandoned_last_month_stays_overdue_there_and_never_touches_this_month(): void
    {
        $this->travelTo(Carbon::parse('2026-09-20 09:00'));
        $this->start($this->a['technician'], 9)->assertRedirect();
        $abandoned = $this->openVisit();
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));

        $this->actingInPanel($this->a['admin']);
        $september = app(MaintenanceAgenda::class)->rows(9, 2026, 'maintenance')->firstWhere('building_id', $this->a['building']->id);
        $this->assertSame('overdue', $september['status']);

        // Octubre: el remito crea su propia visita; la de septiembre sigue en curso y descartable.
        $this->sign($this->a['technician'])->assertSessionHasNoErrors();
        $october = BuildingVisit::sole();
        $this->assertNotSame($abandoned->id, $october->id);
        $this->assertSame(10, (int) $october->month);
        $this->assertTrue($abandoned->fresh()->in_progress);
    }

    public function test_the_office_sees_visits_in_progress_in_the_agenda_and_can_open_and_discard_them(): void
    {
        $this->start($this->a['technician'])->assertRedirect();
        $visit = $this->openVisit();

        $this->actingInPanel($this->a['admin']);
        $this->get(Agenda::getUrl())->assertOk()
            ->assertSee('data-open-visit', false)
            ->assertSee('en curso desde el 14/10')
            ->assertSee(route('visits.show', ['company' => $this->a['company']->slug, 'visit' => $visit->id]), false);

        $this->actingAs($this->a['admin'])->get($this->url("visits/{$visit->id}"))->assertOk()->assertSee('data-discard-visit', false);
    }

    public function test_a_technician_never_discards_photos_uploaded_by_someone_else(): void
    {
        // Septiembre ya tiene remito y una foto: es historial y no se toca.
        $this->sign($this->a['technician'], 9)->assertSessionHasNoErrors();
        $september = BuildingVisit::sole();
        $this->upload($september->id, $this->a['technician'])->assertCreated();

        $this->start($this->a['technician'])->assertRedirect();
        $visit = $this->openVisit();
        $this->upload($visit->id, $this->a['technician'])->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->where('building_visit_id', $visit->id)->sole();

        // El otro técnico (asignado) no puede borrar, al descartar, la foto del primero.
        $this->actingAs($this->other)->delete($this->url("visits/{$visit->id}"))->assertSessionHasErrors('general');
        $this->assertTrue($visit->fresh()->in_progress);
        Storage::disk('local')->assertExists($photo->path);

        // La oficina sí puede descartarla entera.
        $this->actingAs($this->a['admin'])->delete($this->url("visits/{$visit->id}"))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNull(BuildingVisit::withoutGlobalScope('completed')->find($visit->id));
        Storage::disk('local')->assertMissing($photo->path);

        // Septiembre, su remito y su foto siguen intactos.
        $this->assertSame([$september->id], BuildingVisit::withoutGlobalScope('completed')->pluck('id')->all());
        $this->assertSame(1, DeliveryNote::count());
        $kept = VisitPhoto::withoutGlobalScopes()->sole();
        $this->assertSame($september->id, $kept->building_visit_id);
        Storage::disk('local')->assertExists($kept->path);
    }

    public function test_a_photo_that_arrives_after_its_visit_was_discarded_is_not_saved(): void
    {
        $this->start($this->a['technician'])->assertRedirect();
        $visit = $this->openVisit();
        $target = PhotoTarget::forVisit($visit);

        // La visita se descarta mientras la foto sube.
        BuildingVisit::withoutGlobalScopes()->whereKey($visit->id)->delete();

        try {
            app(VisitPhotoService::class)->upload($this->a['technician'], $target, ['photo_section_id' => $this->section()->id, 'stage' => 'before'], UploadedFile::fake()->image('a.jpg', 640, 480));
            $this->fail('La foto no debía guardarse.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('ya no existe', $e->getMessage());
        }

        $this->assertSame(0, VisitPhoto::withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->allFiles('visits'));
    }

    /*
    |--------------------------------------------------------------------------
    | FOTOS DESACTIVADAS: EL HISTORIAL QUEDA COMO ESTÁ (EN EL SERVIDOR)
    |--------------------------------------------------------------------------
    */

    public function test_disabling_photos_freezes_notes_and_deletions_on_the_server_too(): void
    {
        $this->start($this->a['technician'])->assertRedirect();
        $visit = $this->openVisit();
        $this->upload($visit->id, $this->a['technician'])->assertCreated();
        $photo = VisitPhoto::withoutGlobalScopes()->sole();
        $section = ['visit_id' => $visit->id, 'photo_section_id' => $photo->photo_section_id, 'notes' => 'Cambio'];

        $this->a['company']->forceFill(['photos_maintenance' => false])->save();

        $this->actingAs($this->a['technician'])->patchJson($this->url('visit-photos/section'), $section)->assertStatus(422);
        $this->actingAs($this->a['technician'])->deleteJson($this->url("visit-photos/{$photo->id}"))->assertStatus(422);
        $this->assertNull($photo->fresh()->notes);
        Storage::disk('local')->assertExists($photo->path);
        // Se sigue viendo, sin botón para quitarla.
        $this->actingAs($this->a['technician'])->get($this->url("visits/{$visit->id}"))->assertOk()
            ->assertSee($photo->thumbUrl(), false)->assertDontSee('data-remove', false);

        // Habilitadas de nuevo: se puede completar y quitar.
        $this->a['company']->forceFill(['photos_maintenance' => true])->save();
        $this->actingAs($this->a['technician'])->patchJson($this->url('visit-photos/section'), $section)->assertOk();
        $this->assertSame('Cambio', $photo->fresh()->notes);
        $this->actingAs($this->a['technician'])->deleteJson($this->url("visit-photos/{$photo->id}"))->assertOk();
        Storage::disk('local')->assertMissing($photo->path);
    }

    /*
    |--------------------------------------------------------------------------
    | INDICADORES: VISITAS, IGUAL EN EL TÉCNICO, LA AGENDA Y LOS INDICADORES
    |--------------------------------------------------------------------------
    */

    public function test_admin_indicators_count_visits_exactly_like_the_agenda_and_the_technician(): void
    {
        $tech = $this->a['technician'];
        $company = $this->a['company']->id;
        $biweekly = Building::factory()->create(['company_id' => $company, 'elevator_count' => 4]);
        $quarterly = Building::factory()->create(['company_id' => $company, 'elevator_count' => 1]);
        foreach ([$biweekly, $quarterly] as $b) {
            $b->users()->attach($tech->id, ['type' => 'maintenance']);
        }
        $plans = app(VisitPlanService::class);
        $plans->set($biweekly, 'maintenance', 2, 1, CarbonImmutable::parse('2026-10-01'));
        $this->travelTo(Carbon::parse('2026-09-02 09:00'));
        $plans->set($quarterly, 'maintenance', 1, 3, CarbonImmutable::parse('2026-09-01'));
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        BuildingVisit::factory()->create(['building_id' => $quarterly->id, 'user_id' => $tech->id, 'month' => 9, 'year' => 2026]);
        BuildingVisit::factory()->create(['building_id' => $biweekly->id, 'user_id' => $tech->id, 'month' => 10, 'year' => 2026, 'slot' => 1]);
        // Una visita en curso (con fotos, sin remito) no es una visita realizada.
        $this->start($tech)->assertRedirect();

        // Mensual 1 + quincenal 2 + trimestral 0 (hecho en septiembre) = 3 previstas; 1 realizada.
        $techProgress = $this->actingAs($tech)->get($this->url('buildings'))->assertOk()->viewData('progress')['maintenance'];
        $this->actingInPanel($this->a['admin']);
        $agenda = MaintenanceAgenda::progress(app(MaintenanceAgenda::class)->rows(10, 2026, 'maintenance'))['maintenance'];
        $basic = collect(app(Indicators::class)->basic())->keyBy('question')['¿Estamos cumpliendo los mantenimientos de este mes?'];

        $keys = array_flip(['assigned', 'expected', 'done', 'not_done', 'pending', 'percent']);
        $this->assertSame(['assigned' => 3, 'expected' => 3, 'done' => 1, 'not_done' => 0, 'pending' => 2, 'percent' => 33], array_intersect_key($techProgress, $keys));
        $this->assertSame(array_intersect_key($techProgress, $keys), array_intersect_key($agenda, $keys));
        // Antes contaba edificios por estado: 0 de 2 (0 %).
        $this->assertSame('33%', $basic['value']);
        $this->assertStringStartsWith('1 de 3 visitas realizadas', $basic['context']);
    }

    /*
    |--------------------------------------------------------------------------
    | FIRMA
    |--------------------------------------------------------------------------
    */

    public function test_a_blank_or_rejected_signature_never_comes_back_as_signed(): void
    {
        $form = $this->url("delivery-notes/create/building/{$this->a['building']->id}?assignment_type=maintenance&month=10&year=2026");

        // Falta la descripción y la firma está en blanco: la firma no vuelve "firmada".
        $this->from($form)->sign($this->a['technician'], 10, ['description' => '', 'signature' => $this->signaturePng(false)])->assertSessionHasErrors('description');
        $this->actingAs($this->a['technician'])->get($form)->assertOk()->assertSee('id="sig-tech-input" value=""', false);

        // Una válida sí vuelve.
        $valid = $this->validSignature();
        $this->from($form)->sign($this->a['technician'], 10, ['description' => '', 'signature' => $valid])->assertSessionHasErrors('description');
        $this->actingAs($this->a['technician'])->get($form)->assertOk()->assertSee('id="sig-tech-input" value="'.$valid.'"', false)
            ->assertSee("addEventListener('pageshow'", false);
        $this->assertSame(0, DeliveryNote::count());
    }

    /*
    |--------------------------------------------------------------------------
    | EQUIPOS
    |--------------------------------------------------------------------------
    */

    public function test_a_new_unit_never_reuses_a_label_of_the_building(): void
    {
        $building = $this->a['building'];
        Elevator::withoutGlobalScopes()->where('building_id', $building->id)->delete();
        $first = (new Elevator)->forceFill(['building_id' => $building->id, 'company_id' => $building->company_id, 'label' => 'Ascensor 1', 'kind' => 'elevator', 'is_active' => true]);
        $first->save();
        // Dato heredado inconsistente: una etiqueta "Ascensor 2" registrada como montacargas.
        $odd = (new Elevator)->forceFill(['building_id' => $building->id, 'company_id' => $building->company_id, 'label' => 'Ascensor 2', 'kind' => 'freight', 'is_active' => true]);
        $odd->save();

        Elevator::syncFromForm($building->fresh(), [
            ['id' => $first->id], ['id' => $odd->id], ['kind' => 'elevator', 'machine_type' => 'hydraulic'],
        ]);

        $labels = Elevator::withoutGlobalScopes()->where('building_id', $building->id)->orderBy('id')->pluck('machine_type', 'label')->all();
        $this->assertSame(['Ascensor 1' => null, 'Ascensor 2' => null, 'Ascensor 3' => 'hydraulic'], $labels);
        $this->assertSame([2, 1], [(int) $building->fresh()->elevator_count, (int) $building->fresh()->freight_elevator_count]);
    }

    /*
    |--------------------------------------------------------------------------
    | CAMBIOS DE TÉCNICO Y DE FRECUENCIA CON VISITAS YA HECHAS
    |--------------------------------------------------------------------------
    */

    public function test_reassigning_the_building_keeps_the_visit_in_progress_and_its_photos(): void
    {
        $this->start($this->a['technician'])->assertRedirect();
        $visit = $this->openVisit();
        $this->upload($visit->id, $this->a['technician'])->assertCreated();

        // La oficina saca al primer técnico del edificio.
        $this->a['building']->users()->wherePivot('type', 'maintenance')->detach($this->a['technician']->id);

        // No puede firmar el remito de un edificio que ya no tiene asignado...
        $this->sign($this->a['technician'])->assertForbidden();
        // ...pero ve lo que cargó, y el nuevo técnico completa esa misma visita.
        $this->actingAs($this->a['technician'])->get(VisitPhoto::withoutGlobalScopes()->sole()->url())->assertOk();
        $this->sign($this->other)->assertSessionHasNoErrors();
        $this->assertSame([$visit->id], BuildingVisit::withoutGlobalScope('completed')->pluck('id')->all());
        $this->assertSame($this->other->id, (int) BuildingVisit::sole()->user_id);
        $this->assertSame(1, VisitPhoto::withoutGlobalScopes()->whereNotNull('delivery_note_id')->count());
    }

    public function test_changing_between_monthly_and_biweekly_with_visits_done_never_loses_records(): void
    {
        $plans = app(VisitPlanService::class);
        $building = $this->a['building'];
        $eval = fn (int $month) => VisitSchedule::load([$building->id], $month, 2026)->evaluate($building->id, 'maintenance', $month, 2026);

        // Quincenal desde octubre, con las 2 visitas hechas.
        $plans->set($building, 'maintenance', 2, 1, CarbonImmutable::parse('2026-10-01'));
        $this->sign($this->a['technician'])->assertSessionHasNoErrors();
        $this->sign($this->other)->assertSessionHasNoErrors();
        $this->sign($this->a['technician'])->assertSessionHasErrors('general'); // nunca una tercera

        // Vuelve a mensual desde octubre: octubre sigue hecho y ninguna visita se borra.
        $plans->set($building, 'maintenance', 1, 1, CarbonImmutable::parse('2026-10-01'));
        $this->assertSame([1, 1, 0], [$eval(10)['expected'], $eval(10)['done'], $eval(10)['pending']]);
        $this->assertSame(2, BuildingVisit::count());
        $this->assertSame(2, DeliveryNote::count());

        // Septiembre (antes del cambio) nunca se reinterpreta.
        $this->assertSame(1, $eval(9)['expected']);
        // Noviembre: mensual, una visita.
        $this->assertSame([1, 1], [$eval(11)['expected'], $eval(11)['pending']]);
    }
}
