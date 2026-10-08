<?php

namespace Tests\Feature\Flows;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class WorkOrderFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
    }

    private function url(string $path): string
    {
        return "/{$this->a['company']->slug}{$path}";
    }

    public function test_assigned_technician_takes_and_finishes_order(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->actingAs($this->a['technician'])
            ->post($this->url("/work-orders/{$workOrder->id}/start"))
            ->assertRedirect()
            ->assertSessionHas('success');

        $workOrder->refresh();
        $this->assertSame('in_progress', $workOrder->status);
        $this->assertNotNull($workOrder->started_at);
        $this->assertTrue($workOrder->participants()->whereKey($this->a['technician']->id)->exists());

        // Sin remito no se cierra: lo manda a completarlo y firmarlo.
        $this->actingAs($this->a['technician'])
            ->post($this->url("/work-orders/{$workOrder->id}/finish"))
            ->assertRedirect($this->url("/delivery-notes/create/work-order/{$workOrder->id}"))
            ->assertSessionHasErrors('general');

        $this->assertSame('in_progress', $workOrder->fresh()->status);

        // Firmando el remito, la orden queda completada.
        $this->actingAs($this->a['technician'])
            ->post($this->url('/delivery-notes/store'), [
                'building_id' => $this->a['building']->id,
                'work_order_id' => $workOrder->id,
                'assignment_type' => 'work_order',
                'description' => 'Trabajo realizado',
                'elevator_quantity' => 1,
                'freight_elevator_quantity' => 0,
                'signature_name' => 'Técnico',
                'signature' => $this->validSignature(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('completed', $workOrder->fresh()->status);
        $this->assertNotNull($workOrder->fresh()->deliveryNote);
    }

    public function test_unassigned_technician_cannot_take_order(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($other)
            ->post($this->url("/work-orders/{$workOrder->id}/start"))
            ->assertForbidden();

        $this->assertSame('pending', $workOrder->fresh()->status);
    }

    public function test_non_participant_cannot_finish_order(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->actingAs($this->a['technician'])
            ->post($this->url("/work-orders/{$workOrder->id}/finish"))
            ->assertForbidden();

        $this->assertSame('in_progress', $workOrder->fresh()->status);
    }

    public function test_taking_an_order_twice_does_not_reset_it(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create([
            'building_id' => $this->a['building']->id,
            'started_at' => now()->subHour(),
        ]);
        $workOrder->users()->attach($this->a['technician']->id);
        $startedAt = $workOrder->started_at->toDateTimeString();

        $this->actingAs($this->a['technician'])
            ->post($this->url("/work-orders/{$workOrder->id}/start"));

        $workOrder->refresh();
        $this->assertSame('in_progress', $workOrder->status);
        $this->assertSame($startedAt, $workOrder->started_at->toDateTimeString());
    }

    public function test_technician_only_lists_orders_assigned_to_them(): void
    {
        $mine = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor MIO']);
        $mine->users()->attach($this->a['technician']->id);

        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $theirs = WorkOrder::factory()->create(['building_id' => $this->a['building']->id, 'unit' => 'Ascensor AJENO']);
        $theirs->users()->attach($other->id);

        $this->actingAs($this->a['technician'])
            ->get($this->url('/work-orders'))
            ->assertOk()
            ->assertSee('Ascensor MIO')
            ->assertDontSee('Ascensor AJENO');
    }

    public function test_get_requests_cannot_change_state(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->actingAs($this->a['technician'])
            ->get($this->url("/work-orders/{$workOrder->id}/start"))
            ->assertStatus(405);

        $this->assertSame('pending', $workOrder->fresh()->status);
    }
}
