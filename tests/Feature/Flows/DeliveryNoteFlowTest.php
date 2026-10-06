<?php

namespace Tests\Feature\Flows;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\BuildingVisit;
use App\Models\DeliveryNote;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

class DeliveryNoteFlowTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
    }

    private function store(array $overrides = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->a['technician'])
            ->from("/{$this->a['company']->slug}/delivery-notes/create/building/{$this->a['building']->id}")
            ->post("/{$this->a['company']->slug}/delivery-notes/store", array_merge([
                'building_id' => $this->a['building']->id,
                'assignment_type' => 'maintenance',
                'description' => 'Mantenimiento mensual completo',
                'month' => 5,
                'year' => 2026,
                'elevator_quantity' => 2,
                'freight_elevator_quantity' => 0,
                'signature_name' => 'Técnico A',
                'signature' => $this->validSignature(),
            ], $overrides));
    }

    public function test_technician_creates_monthly_maintenance_delivery_note(): void
    {
        $partner = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->store(['participants' => [$this->a['technician']->id, $partner->id]])
            ->assertRedirect("/{$this->a['company']->slug}/delivery-notes")
            ->assertSessionHas('success');

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame('00000001', $note->number);
        $this->assertSame($this->a['company']->id, $note->company_id);
        $this->assertSame($this->a['technician']->id, $note->user_id);
        $this->assertNotNull($note->public_token);

        $visit = BuildingVisit::withoutGlobalScopes()->sole();
        $this->assertSame($note->building_visit_id, $visit->id);
        $this->assertSame('maintenance', $visit->assignment_type);
        $this->assertEqualsCanonicalizing(
            [$this->a['technician']->id, $partner->id],
            $visit->participants()->pluck('users.id')->all()
        );
        $this->assertSame('creator', $visit->participants()->whereKey($this->a['technician']->id)->first()->pivot->role);

        // El compañero ve el remito porque participó.
        $this->actingAs($partner)
            ->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")
            ->assertOk();
    }

    public function test_numbers_are_sequential_per_company(): void
    {
        $b = $this->makeTenant();
        DeliveryNote::factory()->count(3)->create(['building_id' => $b['building']->id]);

        $this->store()->assertSessionHasNoErrors();
        $this->store(['month' => 6])->assertSessionHasNoErrors();

        $numbers = DeliveryNote::withoutGlobalScopes()
            ->where('company_id', $this->a['company']->id)
            ->orderBy('number')
            ->pluck('number')
            ->all();

        $this->assertSame(['00000001', '00000002'], $numbers);
    }

    public function test_cannot_duplicate_monthly_delivery_note(): void
    {
        $this->store()->assertSessionHasNoErrors();
        $this->store()->assertSessionHasErrors('general');

        $this->assertSame(1, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_month_and_year_default_to_current_period(): void
    {
        $this->store(['month' => null, 'year' => null])->assertSessionHasNoErrors();

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame(now()->month, $note->month);
        $this->assertSame(now()->year, $note->year);
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->store(['signature' => 'https://evil.example/pixel.png'.str_repeat('a', 100)])
            ->assertSessionHasErrors('signature');

        $this->store(['client_signature' => 'javascript:alert(1)//'.str_repeat('a', 100)])
            ->assertSessionHasErrors('client_signature');

        $this->store(['month' => 13])->assertSessionHasErrors('month');
        $this->store(['elevator_quantity' => -1])->assertSessionHasErrors('elevator_quantity');
        $this->store(['assignment_type' => 'work_order'])->assertSessionHasErrors('assignment_type');
        $this->store(['assignment_type' => 'admin'])->assertSessionHasErrors('assignment_type');
        $this->store(['description' => str_repeat('x', 5001)])->assertSessionHasErrors('description');

        $this->assertSame(0, DeliveryNote::withoutGlobalScopes()->count());
    }

    public function test_work_order_delivery_note_completes_the_order(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create([
            'building_id' => $this->a['building']->id,
            'type' => 'claim',
        ]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/create/work-order/{$workOrder->id}")
            ->assertOk();

        $this->store([
            'work_order_id' => $workOrder->id,
            'assignment_type' => 'work_order',
        ])->assertSessionHasNoErrors();

        $workOrder->refresh();
        $this->assertSame('completed', $workOrder->status);
        $this->assertNotNull($workOrder->finished_at);

        $note = DeliveryNote::withoutGlobalScopes()->sole();
        $this->assertSame($workOrder->id, $note->work_order_id);
        $this->assertSame('work_order', $note->assignment_type);
        $this->assertNotNull($note->building_visit_id);

        // Una orden no puede tener dos remitos.
        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/create/work-order/{$workOrder->id}")
            ->assertStatus(409);
    }

    public function test_unassigned_technician_cannot_close_work_order(): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);

        $this->actingAs($other)
            ->get("/{$this->a['company']->slug}/delivery-notes/create/work-order/{$workOrder->id}")
            ->assertForbidden();

        $this->store(['work_order_id' => $workOrder->id, 'assignment_type' => 'work_order'], $other)
            ->assertForbidden();

        $this->assertSame('in_progress', $workOrder->fresh()->status);
    }

    public function test_pending_work_order_cannot_get_delivery_note(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->store(['work_order_id' => $workOrder->id, 'assignment_type' => 'work_order'])
            ->assertStatus(409);
    }

    public function test_technician_only_sees_own_or_participated_notes(): void
    {
        $own = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);
        $other = User::factory()->technician()->create(['company_id' => $this->a['company']->id]);
        $foreign = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $other->id,
            'number' => '00000099',
        ]);

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/{$own->number}")
            ->assertOk();

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/{$foreign->number}")
            ->assertNotFound();

        // El admin ve todos los de su empresa.
        $this->actingAs($this->a['admin'])
            ->get("/{$this->a['company']->slug}/delivery-notes/{$foreign->number}/pdf")
            ->assertOk();
    }

    public function test_legacy_unsafe_signatures_are_not_rendered(): void
    {
        $note = DeliveryNote::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
            'signature' => 'https://evil.example/track.png',
            'client_signature' => $this->validSignature(),
        ]);

        $this->actingAs($this->a['technician'])
            ->get("/{$this->a['company']->slug}/delivery-notes/{$note->number}")
            ->assertOk()
            ->assertDontSee('evil.example')
            ->assertSee($this->validSignature(), false);

        $this->actingInPanel($this->a['admin'])
            ->get(DeliveryNoteResource::getUrl('view', ['record' => $note]))
            ->assertOk()
            ->assertSee($this->a['building']->name)
            ->assertSee($this->a['technician']->name)
            ->assertDontSee('evil.example');
    }
}
