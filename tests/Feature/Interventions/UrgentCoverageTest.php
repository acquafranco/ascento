<?php

namespace Tests\Feature\Interventions;

use App\Enums\PermissionArea;
use App\Filament\Resources\OnCall\Pages\ViewOnCallSchedule;
use App\Filament\Resources\OnCall\RelationManagers\WorkOrdersRelationManager;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\ActivityLog;
use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\OnCallSchedule;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Interventions\UrgentCoverage;
use App\Services\OnCall\OnCallService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\MakesSignatures;
use Tests\TestCase;

/**
 * Autorización de urgencias en cualquier edificio (por técnico) y vínculo
 * automático con la guardia: quién la otorga, que se registre, que revocarla
 * corte el acceso en el siguiente pedido, que no amplíe ningún otro permiso y
 * que nunca cruce empresas.
 */
class UrgentCoverageTest extends TestCase
{
    use InteractsWithTenants, MakesSignatures, RefreshDatabase;

    private array $a;

    private array $b;

    private Building $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->actingAs($this->a['admin']);
        $this->unassigned = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Torre Sin Asignar']);
        auth()->logout();
        Carbon::setTestNow('2026-10-25 03:10:00'); // domingo de madrugada
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function open(User $tech, int $buildingId)
    {
        return $this->actingAs($tech)->post(route('interventions.store', ['company' => $tech->company->slug]), [
            'building_id' => $buildingId, 'work_type' => 'claim', 'elevator_number' => 'Ascensor 1', 'channel' => 'whatsapp', 'kind' => 'urgent',
            'description' => 'Reclamo por WhatsApp: ascensor detenido.', 'submission_token' => (string) Str::uuid(),
        ]);
    }

    private function staff(array $areas): User
    {
        $user = User::factory()->create(['company_id' => $this->a['company']->id, 'role' => User::ROLE_STAFF]);
        $keys = collect($areas)->flatMap(fn (array $a) => [$a[0]->key(PermissionArea::VIEW), ...($a[1] ? [$a[0]->key(PermissionArea::MANAGE)] : [])])->all();
        $user->forceFill(['permissions' => $keys])->save();

        return $user->fresh();
    }

    public function test_the_owner_grants_it_from_the_profile_it_is_recorded_and_it_only_opens_interventions(): void
    {
        $tech = $this->a['technician'];

        // Sin autorización ni guardia: el edificio no asignado se rechaza.
        $this->open($tech, $this->unassigned->id)->assertSessionHasErrors('building_id');
        auth()->logout();

        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewUser::class, ['record' => $tech->getRouteKey()])
            ->assertActionVisible('grantUrgentCoverage')->assertActionHidden('revokeUrgentCoverage')
            ->callAction('grantUrgentCoverage');
        $tech = $tech->fresh();
        $this->assertTrue($tech->hasUrgentCoverage());
        $this->assertSame([$this->a['admin']->id, '2026-10-25 03:10'], [$tech->urgent_coverage_granted_by, $tech->urgent_coverage_granted_at->format('Y-m-d H:i')]);
        $log = ActivityLog::where('action', 'technician.urgent_coverage.granted')->sole();
        $this->assertSame([$this->a['admin']->id, $tech->id, $this->a['company']->id], [$log->user_id, $log->subject_id, $log->company_id]);
        $this->get(ViewUser::getUrl(['record' => $tech]))->assertSee('autorizado por '.$this->a['admin']->name);
        auth()->logout();

        // Ahora sí: cualquier edificio de SU empresa, solo para intervenciones.
        $this->actingAs($tech)->get(route('interventions.create', ['company' => $this->slug()]))->assertSee('Tenés autorización para urgencias')
            ->assertViewHas('buildings', fn ($list) => $list->pluck('id')->contains($this->unassigned->id));
        $this->open($tech, $this->unassigned->id)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($this->unassigned->id, WorkOrder::withoutGlobalScopes()->sole()->building_id);

        // No amplía nada más: el mantenimiento o la inspección de ese edificio siguen prohibidos,
        // no aparece entre sus edificios y no puede tocar órdenes de otros técnicos.
        $this->get(route('delivery-notes.building', ['company' => $this->slug(), 'building' => $this->unassigned->id, 'assignment_type' => 'maintenance']))->assertForbidden();
        $this->get(route('buildings.index', ['company' => $this->slug()]))->assertDontSee('Torre Sin Asignar');
        $this->actingAs($this->a['admin']);
        $foreignOrder = WorkOrder::factory()->create(['building_id' => $this->unassigned->id, 'type' => 'claim', 'status' => 'in_progress']);
        auth()->logout();
        $this->actingAs($tech)->get(route('interventions.job.create', ['company' => $this->slug(), 'workOrder' => $foreignOrder->id]))->assertForbidden();
        $this->get(route('delivery-notes.work-order', ['company' => $this->slug(), 'workOrder' => $foreignOrder->id]))->assertForbidden();

        // Nunca otra empresa.
        $this->open($tech, $this->b['building']->id)->assertSessionHasErrors('building_id');
    }

    public function test_revoking_cuts_access_on_the_next_request_even_for_direct_backend_calls(): void
    {
        $tech = $this->a['technician'];
        app(UrgentCoverage::class)->grant($this->a['admin'], $tech);
        $this->open($tech->fresh(), $this->unassigned->id)->assertRedirect();
        $first = WorkOrder::withoutGlobalScopes()->sole();
        auth()->logout();

        $before = [WorkOrder::withoutGlobalScopes()->get()->toArray(), Report::withoutGlobalScopes()->get()->toArray()];
        $this->actingInPanel($this->a['admin']);
        Livewire::test(ViewUser::class, ['record' => $tech->getRouteKey()])->callAction('revokeUrgentCoverage');
        $this->assertFalse($tech->fresh()->hasUrgentCoverage());
        $this->assertNull($tech->fresh()->urgent_coverage_granted_by);
        $this->assertSame(1, ActivityLog::where('action', 'technician.urgent_coverage.revoked')->count());
        auth()->logout();

        // Mismo técnico, siguiente pedido (la misma sesión): ya no aparece ni se acepta por POST directo.
        $this->actingAs($tech)->get(route('interventions.create', ['company' => $this->slug()]))
            ->assertViewHas('buildings', fn ($list) => ! $list->pluck('id')->contains($this->unassigned->id));
        $this->open($tech, $this->unassigned->id)->assertSessionHasErrors('building_id');
        $this->postJson(route('interventions.store', ['company' => $this->slug()]), [
            'building_id' => $this->unassigned->id, 'work_type' => 'claim', 'elevator_number' => 'Ascensor 1', 'channel' => 'phone', 'kind' => 'urgent',
            'description' => 'Intento directo al backend', 'submission_token' => (string) Str::uuid(),
        ])->assertStatus(422);
        $this->assertSame(1, WorkOrder::withoutGlobalScopes()->count());

        // Lo que ya registró no cambia: la orden, su trabajo, sus fotos y su asignación quedan
        // igual, y puede terminar de documentarla (remito con firma) aunque ya no esté autorizado.
        $this->assertSame($before, [WorkOrder::withoutGlobalScopes()->get()->toArray(), Report::withoutGlobalScopes()->get()->toArray()]);
        $this->assertTrue($first->users()->whereKey($tech->id)->exists());
        $job = Report::withoutGlobalScopes()->sole();
        $this->actingAs($tech)->post("/{$this->slug()}/delivery-notes/store", [
            'building_id' => $first->building_id, 'work_order_id' => $first->id, 'assignment_type' => 'work_order',
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0, 'signature_name' => $tech->name, 'signature' => $this->validSignature(),
            'jobs' => [$job->id => ['work_done' => 'Se liberó el freno y se probó.', 'result' => 'resolved']],
        ])->assertSessionHasNoErrors();
        $this->assertSame('completed', $first->fresh()->status);
        $this->assertSame([$first->id, $tech->id], [DeliveryNote::withoutGlobalScopes()->sole()->work_order_id, DeliveryNote::withoutGlobalScopes()->sole()->user_id]);
    }

    public function test_only_the_owner_or_staff_managing_on_call_can_change_it(): void
    {
        $tech = $this->a['technician'];

        // Administrativo que gestiona Guardias (y ve Técnicos): puede.
        $onCallManager = $this->staff([[PermissionArea::OnCall, true], [PermissionArea::Technicians, false]]);
        $this->assertTrue(UrgentCoverage::canManage($onCallManager, $tech));
        $this->actingInPanel($onCallManager);
        Livewire::test(ViewUser::class, ['record' => $tech->getRouteKey()])->callAction('grantUrgentCoverage');
        $this->assertTrue($tech->fresh()->hasUrgentCoverage());
        $this->assertSame($onCallManager->id, $tech->fresh()->urgent_coverage_granted_by);
        auth()->logout();

        // Administrativo que solo ve Técnicos (o solo ve Guardias): no; ni la acción ni el servicio.
        $viewer = $this->staff([[PermissionArea::Technicians, false], [PermissionArea::OnCall, false]]);
        $this->assertFalse(UrgentCoverage::canManage($viewer, $tech));
        $this->actingInPanel($viewer);
        Livewire::test(ViewUser::class, ['record' => $tech->getRouteKey()])->assertActionHidden('revokeUrgentCoverage');
        auth()->logout();

        // Ni el propio técnico, ni otro técnico, ni el dueño de otra empresa, ni el SuperAdmin.
        $peer = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        foreach ([$tech, $peer, $this->b['admin'], User::factory()->superAdmin()->create()] as $actor) {
            $this->assertFalse(UrgentCoverage::canManage($actor, $tech), $actor->email);
            try {
                app(UrgentCoverage::class)->revoke($actor, $tech);
                $this->fail('No debía poder revocar: '.$actor->email);
            } catch (ValidationException) {
            }
        }
        $this->assertTrue($tech->fresh()->hasUrgentCoverage());

        // No se asigna por formularios ni por asignación masiva.
        $peer->fill(['urgent_coverage_granted_at' => now(), 'urgent_coverage_granted_by' => $peer->id])->save();
        $this->assertFalse($peer->fresh()->hasUrgentCoverage());

        // Solo técnicos activos: ni el dueño, ni un administrativo, ni un técnico desactivado.
        $this->assertFalse(UrgentCoverage::canManage($this->a['admin'], $this->a['admin']));
        $this->assertFalse(UrgentCoverage::canManage($this->a['admin'], $onCallManager));
        $peer->delete();
        $this->assertFalse(UrgentCoverage::canManage($this->a['admin'], $peer->fresh()));
    }

    public function test_an_intervention_during_on_call_links_to_the_shift_and_shows_under_its_work(): void
    {
        $tech = $this->a['technician'];
        $this->actingAs($this->a['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Guardia nocturna', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '20:00', 'ends_time' => '08:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        $shift = app(OnCallService::class)->assign($schedule, $tech, now()->subDays(2), null, $this->a['admin']);
        auth()->logout();

        // De guardia (03:10, dentro de 20→08): cualquier edificio de su empresa y queda vinculada al turno.
        $this->open($tech, $this->unassigned->id)->assertSessionHasNoErrors();
        $order = WorkOrder::withoutGlobalScopes()->sole();
        $this->assertSame($shift->id, $order->on_call_shift_id);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(WorkOrdersRelationManager::class, ['ownerRecord' => $schedule, 'pageClass' => ViewOnCallSchedule::class])
            ->assertCanSeeTableRecords([$order]);
        auth()->logout();

        // Fuera del horario de la guardia (mediodía): sin vínculo y, sin autorización, solo sus edificios.
        Carbon::setTestNow('2026-10-25 12:00:00');
        $this->open($tech, $this->unassigned->id)->assertSessionHasErrors('building_id');
        $this->open($tech, $this->a['building']->id)->assertSessionHasNoErrors();
        $this->assertNull(WorkOrder::withoutGlobalScopes()->latest('id')->first()->on_call_shift_id);

        // Con la autorización y fuera de guardia: puede, pero no se inventa una guardia.
        app(UrgentCoverage::class)->grant($this->a['admin'], $tech);
        $this->open($tech->fresh(), $this->unassigned->id)->assertSessionHasNoErrors();
        $this->assertNull(WorkOrder::withoutGlobalScopes()->latest('id')->first()->on_call_shift_id);
    }

    public function test_a_shift_or_authorization_of_another_company_never_crosses_over(): void
    {
        // Técnico de B, autorizado y de guardia en B: nada de A.
        $techB = $this->b['technician'];
        app(UrgentCoverage::class)->grant($this->b['admin'], $techB);
        $this->actingAs($this->b['admin']);
        $schedule = OnCallSchedule::create(['name' => 'Guardia B', 'days' => [1, 2, 3, 4, 5, 6, 7], 'starts_time' => '00:00', 'ends_time' => '00:00', 'technicians_needed' => 1, 'mode' => 'manual', 'is_active' => true]);
        $shiftB = app(OnCallService::class)->assign($schedule, $techB, now()->subDay(), null, $this->b['admin']);
        auth()->logout();

        $this->open($techB->fresh(), $this->unassigned->id)->assertSessionHasErrors('building_id');
        $this->open($techB->fresh(), $this->a['building']->id)->assertSessionHasErrors('building_id');
        $this->assertSame(0, WorkOrder::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        // En su empresa, sí, y vinculada a SU turno.
        $this->open($techB->fresh(), $this->b['building']->id)->assertSessionHasNoErrors();
        $this->assertSame($shiftB->id, WorkOrder::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->sole()->on_call_shift_id);

        // El dueño de A no puede tocar la autorización del técnico de B.
        $this->assertFalse(UrgentCoverage::canManage($this->a['admin'], $techB));

        // Técnico de A autorizado, manipulando pedidos con ids de B desde la URL de SU empresa.
        $techA = $this->a['technician'];
        app(UrgentCoverage::class)->grant($this->a['admin'], $techA);
        $orderB = WorkOrder::withoutGlobalScopes()->where('company_id', $this->b['company']->id)->sole();
        $slugA = $this->a['company']->slug;
        $this->actingAs($techA->fresh())->post(route('interventions.job.store', ['company' => $slugA, 'workOrder' => $orderB->id]), [
            'elevator_number' => 'Ascensor 1', 'channel' => 'other', 'kind' => 'urgent', 'description' => 'Intento con orden ajena', 'submission_token' => (string) Str::uuid(),
        ])->assertNotFound();
        $this->get(route('interventions.job.create', ['company' => $slugA, 'workOrder' => $orderB->id]))->assertNotFound();
        $this->get(route('work-orders.show', ['company' => $slugA, 'workOrder' => $orderB->id]))->assertNotFound();
        $this->post("/{$slugA}/delivery-notes/store", [
            'building_id' => $this->b['building']->id, 'work_order_id' => $orderB->id, 'assignment_type' => 'work_order',
            'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'signature_name' => 'X', 'signature' => $this->validSignature(),
            'description' => 'Firma sobre orden ajena',
        ])->assertNotFound();
        $this->assertSame(1, Report::withoutGlobalScopes()->where('work_order_id', $orderB->id)->count());
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
    }

    private function slug(): string
    {
        return $this->a['company']->slug;
    }
}
