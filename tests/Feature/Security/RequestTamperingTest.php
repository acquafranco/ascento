<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\CompanySettings;
use App\Filament\Resources\Quotes\Pages\EditQuote;
use App\Filament\Resources\Reports\Pages\EditReport;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Models\DeliveryNote;
use App\Models\Quote;
use App\Models\Report;
use App\Models\Subscription;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Campos inesperados en requests y en el estado de Livewire: ninguno
 * de los campos sensibles (empresa, autor, técnico, estado, número,
 * token, suscripción) se puede fijar desde afuera.
 */
class RequestTamperingTest extends TestCase
{
    use InteractsWithTenants, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    public function test_delivery_note_store_ignores_injected_fields(): void
    {
        $this->actingAs($this->a['technician'])
            ->post("/{$this->a['company']->slug}/delivery-notes/store", [
                'building_id' => $this->a['building']->id,
                'assignment_type' => 'maintenance',
                'description' => 'Trabajo',
                'month' => 3,
                'year' => 2026,
                'elevator_quantity' => 1,
                'freight_elevator_quantity' => 0,
                'signature_name' => 'Firma',
                'signature' => $this->validSignature(),
                // Inyectados:
                'company_id' => $this->b['company']->id,
                'user_id' => $this->b['technician']->id,
                'number' => '99999999',
                'public_token' => 'token-elegido',
                'building_visit_id' => 999,
            ])
            ->assertSessionHasNoErrors();

        $note = DeliveryNote::withoutGlobalScopes()->sole();

        $this->assertSame($this->a['company']->id, $note->company_id);
        $this->assertSame($this->a['technician']->id, $note->user_id);
        $this->assertSame('00000001', $note->number);
        $this->assertNotSame('token-elegido', $note->public_token);
        $this->assertNotSame(999, $note->building_visit_id);
    }

    public function test_report_store_ignores_injected_fields(): void
    {
        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Requiere Imagick o GD (procesa la foto antes de guardar).');
        }

        Storage::fake('local');

        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => 'Falla',
            'priority' => 'alta',
            'photos' => [UploadedFile::fake()->image('x.jpg')],
            'company_id' => $this->b['company']->id,
            'user_id' => $this->b['technician']->id,
            'status' => 'resuelto',
        ])->assertSessionHasNoErrors();

        $report = Report::withoutGlobalScopes()->sole();

        $this->assertSame($this->a['company']->id, $report->company_id);
        $this->assertSame($this->a['technician']->id, $report->user_id);
        $this->assertSame('pendiente', $report->fresh()->status);
    }

    public function test_work_order_actions_ignore_injected_status_and_company(): void
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);
        $workOrder->users()->attach($this->a['technician']->id);

        $this->actingAs($this->a['technician'])
            ->post("/{$this->a['company']->slug}/work-orders/{$workOrder->id}/start", [
                'status' => 'completed',
                'company_id' => $this->b['company']->id,
                'finished_at' => now()->toDateTimeString(),
            ]);

        $workOrder->refresh();

        $this->assertSame('in_progress', $workOrder->status);
        $this->assertNull($workOrder->finished_at);
        $this->assertSame($this->a['company']->id, $workOrder->company_id);
    }

    public function test_filament_edit_pages_ignore_injected_sensitive_state(): void
    {
        $quote = Quote::factory()->create([
            'building_id' => $this->a['building']->id,
            'created_by' => $this->a['admin']->id,
        ]);
        $token = (string) $quote->fresh()->public_token;
        $report = Report::factory()->create([
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ]);
        $workOrder = WorkOrder::factory()->create(['building_id' => $this->a['building']->id]);

        $this->actingInPanel($this->a['admin']);

        Livewire::test(EditQuote::class, ['record' => $quote->getRouteKey()])
            ->set('data.created_by', $this->b['admin']->id)
            ->set('data.company_id', $this->b['company']->id)
            ->set('data.public_token', 'token-elegido')
            ->call('save');

        Livewire::test(EditReport::class, ['record' => $report->getRouteKey()])
            ->set('data.user_id', $this->b['technician']->id)
            ->set('data.company_id', $this->b['company']->id)
            ->call('save');

        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->set('data.company_id', $this->b['company']->id)
            ->call('save');

        $quote->refresh();
        $report->refresh();

        $this->assertSame($this->a['admin']->id, $quote->created_by);
        $this->assertSame($this->a['company']->id, $quote->company_id);
        $this->assertSame($token, $quote->public_token);
        $this->assertSame($this->a['technician']->id, $report->user_id);
        $this->assertSame($this->a['company']->id, $report->company_id);
        $this->assertSame($this->a['company']->id, WorkOrder::withoutGlobalScopes()->find($workOrder->id)->company_id);
    }

    public function test_company_settings_cannot_touch_subscription_or_whatsapp_state(): void
    {
        $this->actingInPanel($this->a['admin']);

        Livewire::test(CompanySettings::class)
            ->set('data.whatsapp_connected', true)
            ->set('data.whatsapp_access_token', 'TOKEN-INYECTADO')
            ->set('data.status', 'active')
            ->set('data.subscription', ['status' => 'active'])
            ->call('save')
            ->assertHasNoFormErrors();

        $company = $this->a['company']->fresh();

        $this->assertFalse((bool) $company->whatsapp_connected);
        $this->assertNull($company->whatsapp_access_token);
        $this->assertSame(0, Subscription::count());
    }
}
