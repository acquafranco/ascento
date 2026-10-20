<?php

namespace Tests\Feature\Stage7;

use App\Enums\PlanFeature;
use App\Filament\Resources\ParachuteTests\ParachuteTestResource;
use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Elevator;
use App\Models\ParachuteSchedule;
use App\Models\ParachuteTest;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\App\ParachuteReminderNotification;
use App\Services\Parachute\ParachuteService;
use App\Support\Plans\PlanGuard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Pruebas de paracaídas del lado del técnico: ve cuándo corresponde cada
 * prueba sin entrar edificio por edificio, la registra (si la hizo) al firmar
 * el remito, queda vinculada a ese remito y nunca se da por hecha una prueba
 * que no se registró. Sin periodicidad configurada, se dice que falta.
 */
class ParachuteTechnicianTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    private Elevator $p1;

    private Elevator $p2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['webpush.vapid.public_key' => null]);
        $this->travelTo(Carbon::parse('2026-10-14 09:00'));
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
        $this->giveCompanyPlan($this->a['company'], 'empresa');
        $this->giveCompanyPlan($this->b['company'], 'empresa');
        $this->a['building']->update(['elevator_count' => 2, 'name' => 'Torre Norte']);
        // El técnico hace la INSPECCIÓN de este edificio.
        $this->a['building']->users()->attach($this->a['technician']->id, ['type' => 'inspection']);

        // Los equipos se crean solos con la cantidad del edificio.
        $this->p1 = Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->where('label', 'Ascensor 1')->firstOrFail();
        $this->p2 = Elevator::withoutGlobalScopes()->where('building_id', $this->a['building']->id)->where('label', 'Ascensor 2')->firstOrFail();
    }

    private function elevator(array $tenant, string $label, $building = null): Elevator
    {
        $elevator = (new Elevator)->forceFill(['building_id' => ($building ?? $tenant['building'])->id, 'company_id' => $tenant['company']->id, 'label' => $label, 'kind' => 'elevator', 'is_active' => true]);
        $elevator->save();

        return $elevator;
    }

    private function schedule(Elevator $elevator, ?int $months, array $extra = []): void
    {
        (new ParachuteSchedule)->forceFill(['company_id' => $elevator->company_id, 'elevator_id' => $elevator->id, 'interval_months' => $months, 'notice_days' => 30] + $extra)->save();
    }

    private function pastTest(Elevator $elevator, string $date, string $result = ParachuteTest::SATISFACTORY): void
    {
        (new ParachuteTest)->forceFill(['company_id' => $elevator->company_id, 'elevator_id' => $elevator->id, 'building_id' => $elevator->building_id,
            'performed_on' => $date, 'result' => $result, 'performed_by_name' => 'Inspector externo'])->save();
    }

    private function signInspection(User $user, array $parachute, bool $performed = true, int $month = 10)
    {
        return $this->actingAs($user)->post("/{$this->a['company']->slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id,
            'assignment_type' => 'inspection',
            'submission_token' => (string) Str::uuid(),
            'description' => 'Inspección mensual',
            'month' => $month, 'year' => 2026,
            'elevator_quantity' => 2, 'freight_elevator_quantity' => 0,
            'performed' => $performed ? '1' : '0',
            'signature_name' => 'Técnico',
            'signature' => $this->validSignature(),
            'parachute' => $parachute,
        ]);
    }

    public function test_the_technician_sees_when_each_test_is_due_without_opening_each_building(): void
    {
        // P1: cada 12 meses, la última satisfactoria hace casi un año → próxima.
        $this->schedule($this->p1, 12);
        $this->pastTest($this->p1, '2025-10-30');
        // P2: sin periodicidad (ni del ascensor ni del cliente) → falta definirla, sin inventar una.
        // Otro edificio donde es el inspector asignado (sin la inspección del edificio).
        $other = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Edificio Sur']);
        $p3 = $this->elevator($this->a, 'Ascensor Sur', $other);
        $this->schedule($p3, 6, ['inspector_id' => $this->a['technician']->id]);
        $this->pastTest($p3, '2026-01-10');
        // Un edificio que no sigue: no aparece.
        $foreign = Building::factory()->create(['company_id' => $this->a['company']->id, 'name' => 'Edificio Ajeno']);
        $this->elevator($this->a, 'Ascensor Ajeno', $foreign);
        // Otra empresa: nunca.
        $this->elevator($this->b, 'Ascensor B');

        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['technician'])->get("/{$slug}/paracaidas")->assertOk()
            ->assertSeeInOrder(['Vencidas', 'Ascensor Sur', 'Próximas', 'Ascensor 1', 'Prevista para el 30/10/2026', 'Sin periodicidad definida', 'Ascensor 2', 'Falta que la oficina defina la periodicidad'])
            ->assertDontSee('Ascensor Ajeno')->assertDontSee('Ascensor B');

        // El inicio lo avisa y lleva a la pestaña.
        $this->get("/{$slug}/dashboard")->assertOk()->assertSee('Pruebas de paracaídas: 2 para hacer')->assertSee('1 vencidas · 1 próximas');

        // El recordatorio del técnico lleva a esa pestaña.
        app(ParachuteService::class)->sendReminders();
        $notice = $this->a['technician']->notifications()->where('type', ParachuteReminderNotification::class)->first();
        $this->assertSame("/{$slug}/paracaidas", $notice->data['path'] ?? null);
    }

    public function test_the_test_is_recorded_only_when_chosen_and_stays_linked_to_the_remito(): void
    {
        $this->schedule($this->p1, 12);
        $this->pastTest($this->p1, '2025-09-01');

        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['technician'])
            ->get("/{$slug}/delivery-notes/create/building/{$this->a['building']->id}?assignment_type=inspection&month=10&year=2026")
            ->assertOk()->assertSee('Prueba de paracaídas')->assertSee('Vencida desde el 01/09/2026')->assertSee('No se hizo en esta visita');

        $this->signInspection($this->a['technician'], [$this->p1->id => ParachuteTest::SATISFACTORY, $this->p2->id => ''])->assertSessionHasNoErrors();

        $note = DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->sole();
        $tests = ParachuteTest::withoutGlobalScopes()->where('delivery_note_id', $note->id)->get();
        $this->assertCount(1, $tests); // «No se hizo» no crea nada
        $this->assertSame($this->p1->id, $tests->sole()->elevator_id);
        $this->assertSame('2026-10-14', $tests->sole()->performed_on->toDateString());
        $this->assertSame($this->a['technician']->id, $tests->sole()->performed_by_id);
        $this->assertSame($this->a['technician']->id, $tests->sole()->recorded_by);

        // Queda al día y el remito la muestra.
        $row = app(ParachuteService::class)->overview($this->a['company']->id)->firstWhere('elevator.id', $this->p1->id);
        $this->assertSame(ParachuteService::OK, $row['status']);
        $this->assertSame('2027-10-14', $row['next_due']->toDateString());
        $this->get("/{$slug}/delivery-notes/{$note->number}")->assertOk()->assertSee('Prueba de paracaídas')->assertSee('Ascensor 1')->assertSee('Satisfactoria');

        // La oficina ve de qué remito salió.
        $this->actingInPanel($this->a['admin']);
        $this->get(ParachuteTestResource::getUrl('view', ['record' => $tests->sole()]))->assertOk()->assertSee('Remito '.$note->number);
    }

    public function test_without_choosing_a_result_nothing_is_marked_as_done(): void
    {
        $this->usingAscentoSince($this->a, '2026-01-01');
        $this->schedule($this->p1, 12);
        $this->signInspection($this->a['technician'], [])->assertSessionHasNoErrors();
        $this->signInspection($this->a['technician'], [$this->p1->id => '', $this->p2->id => null], month: 9)->assertSessionHasNoErrors();
        $this->assertSame(2, DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());

        $this->assertSame(0, ParachuteTest::withoutGlobalScopes()->count());
        $this->assertSame(ParachuteService::NO_RECORD, app(ParachuteService::class)->overview($this->a['company']->id)->firstWhere('elevator.id', $this->p1->id)['status']);
    }

    public function test_invalid_elevators_results_or_a_job_not_done_never_record_a_test_nor_the_remito(): void
    {
        $foreign = $this->elevator($this->b, 'Ascensor B');
        $otherBuilding = $this->elevator($this->a, 'De otro edificio', Building::factory()->create(['company_id' => $this->a['company']->id]));

        foreach ([[$foreign->id => 'satisfactory'], [$otherBuilding->id => 'satisfactory'], [$this->p1->id => 'perfecta']] as $parachute) {
            $this->signInspection($this->a['technician'], $parachute)->assertSessionHasErrors();
        }
        // Trabajo marcado como NO realizado: no se registra la prueba (ni el remito).
        $this->signInspection($this->a['technician'], [$this->p1->id => 'satisfactory'], performed: false)->assertSessionHasErrors('parachute');

        $this->assertSame(0, ParachuteTest::withoutGlobalScopes()->count());
        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->where('company_id', $this->a['company']->id)->count());
    }

    public function test_in_a_maintenance_job_only_the_assigned_inspector_can_record_it(): void
    {
        $this->schedule($this->p2, 12, ['inspector_id' => $this->a['technician']->id]);
        $order = WorkOrder::factory()->inProgress()->create(['company_id' => $this->a['company']->id, 'building_id' => $this->a['building']->id, 'type' => 'maintenance']);
        $order->users()->attach($this->a['technician']->id);

        $slug = $this->a['company']->slug;
        $this->actingAs($this->a['technician'])->get("/{$slug}/delivery-notes/create/work-order/{$order->id}")->assertOk()
            ->assertSee('parachute['.$this->p2->id.']', false)->assertDontSee('parachute['.$this->p1->id.']', false);

        $sign = fn (array $parachute) => $this->post("/{$slug}/delivery-notes/store", [
            'building_id' => $this->a['building']->id, 'work_order_id' => $order->id, 'assignment_type' => 'work_order',
            'description' => 'Trabajo', 'elevator_quantity' => 1, 'freight_elevator_quantity' => 0, 'performed' => '1',
            'signature_name' => 'Técnico', 'signature' => $this->validSignature(), 'parachute' => $parachute,
        ]);
        $sign([$this->p1->id => 'satisfactory'])->assertSessionHasErrors('parachute');
        $this->assertSame('in_progress', $order->fresh()->status);

        $sign([$this->p2->id => 'unsatisfactory'])->assertSessionHasNoErrors();
        $test = ParachuteTest::withoutGlobalScopes()->sole();
        $this->assertSame($this->p2->id, $test->elevator_id);
        $this->assertSame(ParachuteService::FAILED, app(ParachuteService::class)->overview($this->a['company']->id)->firstWhere('elevator.id', $this->p2->id)['status']);
    }

    public function test_the_tab_follows_the_plan_and_the_company(): void
    {
        $this->giveCompanyPlan($this->a['company'], 'basico');
        $allowed = PlanGuard::companyAllows($this->a['company']->id, PlanFeature::ParachuteTests);

        $response = $this->actingAs($this->a['technician'])->get("/{$this->a['company']->slug}/paracaidas");
        $allowed ? $response->assertOk() : $response->assertNotFound();

        // La URL de otra empresa no muestra nada de ella.
        $this->actingAs($this->a['technician'])->get("/{$this->b['company']->slug}/paracaidas")->assertRedirect();
    }
}
