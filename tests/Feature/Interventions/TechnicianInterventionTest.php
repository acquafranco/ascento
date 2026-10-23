<?php

namespace Tests\Feature\Interventions;

use App\Filament\Resources\Reports\ReportResource;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Resources\WorkOrders\RelationManagers\JobsRelationManager;
use App\Models\Building;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\OnCallSchedule;
use App\Models\Report;
use App\Models\ReportPhoto;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Interventions\InterventionService;
use App\Services\OnCall\OnCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Intervenciones iniciadas por el técnico (urgencias y trabajos no
 * programados), de punta a punta: registrar desde Reportes sin orden previa,
 * sumar otros trabajos de la misma visita, hacer el remito con la firma del
 * cliente y que la oficina vea todo, sin cruces entre empresas ni técnicos.
 */
class TechnicianInterventionTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        auth()->logout();
        // Sábado a la noche: no hay nadie en la oficina.
        Carbon::setTestNow('2026-10-24 22:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function slug(array $tenant = []): string
    {
        return ($tenant ?: $this->a)['company']->slug;
    }

    private function open(User $tech, array $overrides = [], ?array $tenant = null)
    {
        $tenant ??= $this->a;

        return $this->actingAs($tech)->post(route('interventions.store', ['company' => $tenant['company']->slug]), [
            'building_id' => $tenant['building']->id,
            'elevator_number' => 'Ascensor 1',
            'channel' => 'whatsapp',
            'kind' => 'urgent',
            'description' => 'El encargado avisa por WhatsApp que el ascensor 1 no baja del piso 5.',
            'submission_token' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    private function addJob(User $tech, WorkOrder $order, array $overrides = [])
    {
        return $this->actingAs($tech)->post(route('interventions.job.store', ['company' => $this->slug(), 'workOrder' => $order->id]), [
            'elevator_number' => 'Ascensor 2',
            'channel' => 'detected',
            'kind' => 'unscheduled',
            'description' => 'Botonera de cabina del ascensor 2 sin luz.',
            'submission_token' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    private function remito(User $tech, WorkOrder $order, array $jobs, array $overrides = [])
    {
        return $this->actingAs($tech)->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $order->building_id,
            'work_order_id' => $order->id,
            'assignment_type' => 'work_order',
            'elevator_quantity' => 2,
            'freight_elevator_quantity' => 0,
            'signature_name' => $tech->name,
            'signature' => $this->validSignature(),
            'client_signature' => $this->validSignature(),
            'client_signature_name' => 'Marta, encargada',
            'jobs' => $jobs,
            ...$overrides,
        ]);
    }

    public function test_a_technician_registers_an_urgency_without_an_office_order_and_signs_its_remito(): void
    {
        $tech = $this->a['technician'];
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());

        // 1. Desde Reportes: el acceso está a la vista y el formulario ofrece sus edificios.
        $this->actingAs($tech)->get(route('reports.index', ['company' => $this->slug()]))->assertOk()
            ->assertSee('Registrar intervención o urgencia')->assertSee(route('interventions.create', ['company' => $this->slug()]), false);
        $this->get(route('interventions.create', ['company' => $this->slug()]))->assertOk()
            ->assertViewHas('buildings', fn ($list) => $list->pluck('id')->all() === [$this->a['building']->id]);

        // 2–3. Se registra con foto y se crea la orden (la visita), asignada a él y en curso.
        $response = $this->open($tech, ['photos' => [UploadedFile::fake()->image('falla.jpg', 800, 600)]]);
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $response->assertRedirect(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]));
        $this->assertSame(['claim', 'in_progress', 'urgent', WorkOrder::ORIGIN_TECHNICIAN, $tech->id, $this->a['company']->id, $this->a['building']->id],
            [$order->type, $order->status, $order->priority, $order->origin, $order->created_by, $order->company_id, $order->building_id]);
        $this->assertTrue($order->users()->whereKey($tech->id)->exists());
        $this->assertNotNull($order->started_at);

        $job = Report::withoutGlobalScopes()->sole();
        $this->assertSame([Report::KIND_INTERVENTION, $order->id, 'whatsapp', 'critica', 'pendiente', $tech->id], [$job->kind, $job->work_order_id, $job->channel, $job->priority, $job->status, $job->user_id]);
        $this->assertSame(1, $job->photos()->count());

        // La orden muestra el trabajo y deja sumar otro antes del remito.
        $this->get(route('work-orders.show', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertOk()
            ->assertSee('Trabajos de esta visita (1)')->assertSee('Iniciada por vos')->assertSee('Agregar otro trabajo de esta visita')->assertSee('Hacer remito y finalizar');

        // 4–5. El remito sale desde su portal, con el formulario de siempre y la firma del cliente.
        $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertOk()
            ->assertSee('Trabajos de esta visita (1)')->assertSee('Cómo quedó');
        $this->remito($tech, $order, [$job->id => ['work_done' => 'Se reseteó el variador y se cambió el contactor de bajada.', 'result' => 'resolved']])
            ->assertSessionHasNoErrors()->assertRedirect();

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame([$order->id, $tech->id, $this->a['company']->id, 'Marta, encargada'], [$note->work_order_id, $note->user_id, $note->company_id, $note->client_signature_name]);
        $this->assertNotNull($note->client_signature);
        $this->assertStringContainsString('Se reseteó el variador', $note->description); // resumen armado con los trabajos
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame(['resuelto', 'resolved'], [$job->fresh()->status, $job->fresh()->result]);
        $this->assertSame(1, BuildingVisit::withoutGlobalScopes()->where('work_order_id', $order->id)->count());

        // El remito (técnico y enlace público) lista el trabajo con su resultado.
        $this->get(route('delivery-notes.show', ['company' => $this->slug(), 'deliveryNote' => $note]))->assertOk()
            ->assertSee('Intervención del técnico')->assertSee('Trabajos realizados en la visita')->assertSee('Resuelto')->assertSee('no baja del piso 5');
        auth()->logout();
        $this->get(route('delivery-notes.public', ['company' => $this->slug(), 'token' => $note->public_token]))->assertOk()->assertSee('Trabajos realizados en la visita');
    }

    public function test_several_jobs_in_the_same_visit_go_to_one_remito_without_duplicates_or_wrong_links(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();

        // Dos problemas más en la misma visita (uno detectado, otro que le cuenta el encargado).
        $this->addJob($tech, $order)->assertRedirect();
        $this->addJob($tech, $order, ['elevator_number' => 'Ascensor 1', 'channel' => 'building', 'kind' => 'unscheduled', 'description' => 'Ruido en puerta de palier PB.'])->assertRedirect();
        $jobs = Report::withoutGlobalScopes()->orderBy('id')->get();
        $this->assertCount(3, $jobs);
        $this->assertSame([$order->id], $jobs->pluck('work_order_id')->unique()->values()->all());
        $this->assertSame(['whatsapp', 'detected', 'building'], $jobs->pluck('channel')->all());
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->count());

        // Sin cerrar cada trabajo, no se firma; un trabajo de otra orden no se acepta.
        [$other] = [$this->open($tech, ['submission_token' => (string) Str::uuid(), 'description' => 'Otra visita distinta, otro día.'])];
        $otherOrder = WorkOrder::withoutGlobalScopes()->latest('id')->first();
        $otherJob = Report::withoutGlobalScopes()->where('work_order_id', $otherOrder->id)->sole();
        $this->remito($tech, $order, [$jobs[0]->id => ['work_done' => 'Listo el primero.', 'result' => 'resolved']])
            ->assertSessionHasErrors(["jobs.{$jobs[1]->id}.work_done", "jobs.{$jobs[2]->id}.result"]);
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());

        $this->remito($tech, $order, [
            $jobs[0]->id => ['work_done' => 'Contactor de bajada cambiado.', 'result' => 'resolved'],
            $jobs[1]->id => ['work_done' => 'Se cambió la lámpara de la botonera.', 'result' => 'resolved'],
            $jobs[2]->id => ['work_done' => 'Se ajustó el patín, falta repuesto.', 'result' => 'follow_up'],
            $otherJob->id => ['work_done' => 'Intento de cerrar un trabajo ajeno.', 'result' => 'resolved'],
        ], ['description' => 'Visita por urgencia; se resolvieron dos temas más.'])->assertSessionHasNoErrors();

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame($order->id, $note->work_order_id);
        $this->assertSame(['resuelto', 'resuelto', 'en_revision'], Report::withoutGlobalScopes()->whereIn('id', $jobs->pluck('id'))->orderBy('id')->pluck('status')->all());
        // El trabajo de la otra orden no se tocó.
        $this->assertNull($otherJob->fresh()->work_done);
        $this->assertSame('in_progress', $otherOrder->fresh()->status);

        // Con remito firmado, la visita no admite más trabajos ni otro remito.
        $this->addJob($tech, $order)->assertSessionHasErrors('general');
        $this->get(route('interventions.job.create', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertForbidden();
        $this->assertSame(3, Report::withoutGlobalScopes()->where('work_order_id', $order->id)->count());
        $this->remito($tech, $order, [])->assertStatus(409);
        $this->assertSame(1, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_repeated_submissions_do_not_duplicate_anything(): void
    {
        $tech = $this->a['technician'];
        $token = (string) Str::uuid();

        $this->open($tech, ['submission_token' => $token])->assertRedirect();
        $this->open($tech, ['submission_token' => $token])->assertRedirect();
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->count());
        $this->assertSame(1, Report::withoutGlobalScopes()->count());

        $order = WorkOrder::withoutGlobalScopes()->sole();
        $jobToken = (string) Str::uuid();
        $this->addJob($tech, $order, ['submission_token' => $jobToken]);
        $this->addJob($tech, $order, ['submission_token' => $jobToken]);
        $this->assertSame(2, Report::withoutGlobalScopes()->count());
    }

    public function test_technicians_only_use_buildings_they_may_attend_and_on_call_covers_the_rest(): void
    {
        $tech = $this->a['technician'];
        $this->actingAs($this->a['admin']);
        $unassigned = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Torre Sin Asignar']);
        auth()->logout();

        // No asignado y sin guardia: ni aparece ni se acepta.
        $this->actingAs($tech)->get(route('interventions.create', ['company' => $this->slug()]))->assertDontSee('Torre Sin Asignar');
        $this->open($tech, ['building_id' => $unassigned->id])->assertSessionHasErrors('building_id');
        // Un edificio de otra empresa, tampoco.
        $this->open($tech, ['building_id' => $this->b['building']->id])->assertSessionHasErrors('building_id');
        // Un equipo que no existe en el edificio, tampoco.
        $this->open($tech, ['elevator_number' => 'Ascensor 9'])->assertSessionHasErrors('elevator_number');
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->count());

        // De guardia ahora: puede atender cualquier edificio de su empresa.
        $this->actingAs($this->a['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Fin de semana', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '00:00', 'ends_time' => '23:59', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        app(OnCallService::class)->assign($schedule, $tech, now()->subHour(), null, $this->a['admin']);
        auth()->logout();
        $this->actingAs($tech)->get(route('interventions.create', ['company' => $this->slug()]))->assertSee('Torre Sin Asignar')->assertSee('Estás de guardia');
        $this->open($tech, ['building_id' => $unassigned->id])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($unassigned->id, WorkOrder::withoutGlobalScopes()->sole()->building_id);
    }

    public function test_no_cross_company_or_cross_technician_access(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech);
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $job = Report::withoutGlobalScopes()->sole();

        // Otro técnico de la MISMA empresa: no suma trabajos, no abre ni firma su remito, no ve el reporte.
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $this->addJob($other, $order)->assertSessionHasErrors('general');
        $this->get(route('interventions.job.create', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertForbidden();
        $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $order->id]))->assertForbidden();
        $this->remito($other, $order, [$job->id => ['work_done' => 'Firmo por otro.', 'result' => 'resolved']])->assertForbidden();
        $this->get(route('reports.show', ['company' => $this->slug(), 'report' => $job->id]))->assertForbidden();

        // Un técnico de OTRA empresa: ni con la URL de su empresa ni con la de la ajena.
        $foreign = $this->b['technician'];
        // (la empresa ajena en la URL ni se reconoce: 404, como el resto de la app)
        $this->assertContains($this->actingAs($foreign)->post(route('interventions.job.store', ['company' => $this->slug(), 'workOrder' => $order->id]), [])->status(), [302, 403, 404]);
        $this->actingAs($foreign)->post(route('interventions.job.store', ['company' => $this->b['company']->slug, 'workOrder' => $order->id]), [
            'elevator_number' => 'Ascensor 1', 'channel' => 'other', 'kind' => 'urgent', 'description' => 'Intento cruzado', 'submission_token' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->assertContains($this->actingAs($foreign)->get(route('interventions.create', ['company' => $this->slug()]))->status(), [302, 403, 404]);
        $this->assertSame(1, Report::withoutGlobalScopes()->count());

        // Datos inyectados (empresa, autor, tipo, orden) se ignoran.
        $this->open($tech, ['company_id' => $this->b['company']->id, 'user_id' => $foreign->id, 'kind_report' => 'inspection', 'work_order_id' => 999, 'origin' => 'office', 'created_by' => $foreign->id]);
        $last = WorkOrder::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([$this->a['company']->id, $tech->id, WorkOrder::ORIGIN_TECHNICIAN], [$last->company_id, $last->created_by, $last->origin]);
        $this->assertSame(Report::KIND_INTERVENTION, Report::withoutGlobalScopes()->latest('id')->first()->kind);

        // Portal de clientes, proveedores y administrativos: no abren intervenciones.
        $this->assertFalse(InterventionService::canUse(User::factory()->create(['company_id' => null])));
        $portal = User::factory()->create(['company_id' => $this->a['company']->id]);
        $portal->forceFill(['role' => 'client'])->save();
        $this->assertFalse(InterventionService::canUse($portal->fresh()));
        $this->assertNotSame(200, $this->actingAs($portal->fresh())->get(route('interventions.create', ['company' => $this->slug()]))->status());
        $this->assertSame(2, WorkOrder::withoutGlobalScopes()->count());
    }

    public function test_the_office_sees_who_opened_each_intervention_its_jobs_photos_signatures_and_pending_documentation(): void
    {
        $tech = $this->a['technician'];
        $this->open($tech, ['photos' => [UploadedFile::fake()->image('antes.jpg', 800, 600)]]);
        $pendingOrder = WorkOrder::withoutGlobalScopes()->sole();
        $job = Report::withoutGlobalScopes()->sole();

        // Una orden de la oficina, para distinguirlas.
        $this->actingInPanel($this->a['admin']);
        $officeOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'maintenance']);

        Livewire::test(ListWorkOrders::class)
            ->assertCanSeeTableRecords([$pendingOrder, $officeOrder])
            ->assertSee('Técnico: '.$tech->name)->assertSee('Oficina')
            ->filterTable('origin', 'technician')->assertCanSeeTableRecords([$pendingOrder])->assertCanNotSeeTableRecords([$officeOrder])
            ->resetTableFilters()
            ->filterTable('pending_documentation')->assertCanSeeTableRecords([$pendingOrder])->assertCanNotSeeTableRecords([$officeOrder]);

        Livewire::test(JobsRelationManager::class, ['ownerRecord' => $pendingOrder, 'pageClass' => EditWorkOrder::class])
            ->assertCanSeeTableRecords([$job])->assertSee('WhatsApp')->assertSee('Pendiente');

        // El reporte de la intervención: canal, orden y remito; y su foto con los controles de siempre.
        $this->get(ReportResource::getUrl('view', ['record' => $job->id]))->assertOk()
            ->assertSee('Intervención del técnico')->assertSee('WhatsApp')->assertSee('iniciada por el técnico')->assertSee('Todavía sin remito');
        $photo = ReportPhoto::sole();
        $this->get(route('reports.photos.show', ['report' => $job->id, 'photo' => $photo->id]))->assertOk();
        auth()->logout();
        $this->actingAs($this->b['admin'])->get(route('reports.photos.show', ['report' => $job->id, 'photo' => $photo->id]))->assertNotFound();
        auth()->logout();

        // Firmado el remito: deja de figurar como pendiente y la oficina ve el resultado.
        $this->remito($tech, $pendingOrder, [$job->id => ['work_done' => 'Reparado.', 'result' => 'partial']]);
        auth()->logout();
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ListWorkOrders::class)->filterTable('pending_documentation')->assertCanNotSeeTableRecords([$pendingOrder]);
        $this->get(ReportResource::getUrl('view', ['record' => $job->id]))->assertSee('Resuelto parcialmente')->assertSee('Reparado.');
        $this->assertSame('en_revision', $job->fresh()->status);
    }

    public function test_office_orders_regular_visits_and_existing_remitos_keep_working(): void
    {
        $tech = $this->a['technician'];

        // Orden de la oficina SIN trabajos: el remito pide «Trabajo realizado» como siempre.
        $this->actingAs($this->a['admin']);
        $office = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'status' => 'pending']);
        $office->users()->attach($tech->id);
        auth()->logout();
        $this->actingAs($tech)->post(route('work-orders.start', ['company' => $this->slug(), 'workOrder' => $office->id]));
        $this->assertSame('in_progress', $office->fresh()->status);
        $this->remito($tech, $office, [], ['description' => ''])->assertSessionHasErrors('description');
        $this->remito($tech, $office, [], ['description' => 'Se cambió el pulsador.'])->assertSessionHasNoErrors();
        $this->assertSame('completed', $office->fresh()->status);
        $this->assertNull($office->fresh()->origin);

        // Una orden de la oficina en curso admite trabajos adicionales de la visita (el técnico asignado).
        $this->actingAs($this->a['admin']);
        $second = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'type' => 'claim', 'status' => 'in_progress']);
        $second->users()->attach($tech->id);
        auth()->logout();
        $this->addJob($tech, $second)->assertRedirect();
        $this->assertNull($second->fresh()->origin); // sigue siendo de la oficina
        $this->assertSame(1, $second->jobs()->count());

        // Mantenimiento mensual del edificio asignado: el flujo de siempre, sin trabajos ni orden.
        $this->actingAs($tech)->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $this->a['building']->id, 'assignment_type' => 'maintenance', 'description' => 'Mantenimiento mensual.',
            'month' => 10, 'year' => 2026, 'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'signature_name' => $tech->name, 'signature' => $this->validSignature(), 'submission_token' => (string) Str::uuid(),
        ])->assertSessionHasNoErrors();
        $maintenance = DeliveryNote::withoutGlobalScopes()->whereNull('work_order_id')->sole();
        $this->assertSame('maintenance', $maintenance->assignment_type);
        $this->assertSame(1, BuildingVisit::withoutGlobalScopes()->where('assignment_type', 'maintenance')->count());

        // Las intervenciones no consumen el cupo de reportes ni se cuentan como fallas.
        $this->assertSame(0, Report::incidents()->withoutGlobalScopes()->count());
    }

    public function test_an_urgency_is_never_blocked_by_the_monthly_report_quota(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'inicial');
        $tech = $this->a['technician'];
        $this->actingAs($tech);
        $limit = (int) $this->a['company']->fresh()->plan()->maxReportsPerMonth();
        for ($i = 0; $i < $limit; $i++) {
            Report::create(['company_id' => $this->a['company']->id, 'user_id' => $tech->id, 'building_id' => $this->a['building']->id, 'elevator_number' => 'Ascensor 1', 'description' => 'Falla '.$i, 'priority' => 'baja']);
        }

        // Cupo de reportes agotado: la falla común se frena, la urgencia no.
        $this->get(route('reports.create', ['company' => $this->slug()]))->assertSee('reportes');
        $this->open($tech)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->count());
    }
}
