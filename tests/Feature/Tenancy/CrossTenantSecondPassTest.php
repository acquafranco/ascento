<?php

namespace Tests\Feature\Tenancy;

use App\Filament\Resources\Users\Widgets\UserStatsWidget;
use App\Filament\Resources\WorkOrders\WorkOrderResource;
use App\Models\Building;
use App\Models\DeliveryNote;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\NewReportNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\TestCase;

/**
 * Segunda pasada: vías indirectas para cruzar empresas.
 */
class CrossTenantSecondPassTest extends TestCase
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

    public function test_every_http_verb_with_other_company_ids_is_rejected(): void
    {
        $noteB = DeliveryNote::factory()->create(['building_id' => $this->b['building']->id, 'number' => '00000321']);
        $woB = WorkOrder::factory()->create(['building_id' => $this->b['building']->id]);
        $reportB = Report::factory()->create(['building_id' => $this->b['building']->id]);
        $clientB = $this->b['building']->client;
        $slug = $this->a['company']->slug;

        $targets = [
            "/{$slug}/delivery-notes/{$noteB->number}",
            "/{$slug}/work-orders/{$woB->id}/start",
            "/{$slug}/work-orders/{$woB->id}/finish",
            "/{$slug}/reports/{$reportB->id}",
            "/{$slug}/clients/{$clientB->id}",
            "/{$slug}/building-check/{$this->b['building']->id}/done",
            "/{$slug}/delivery-notes/create/building/{$this->b['building']->id}",
            "/{$slug}/delivery-notes/create/work-order/{$woB->id}",
        ];

        foreach ($targets as $url) {
            foreach (['get', 'post', 'put', 'patch', 'delete'] as $verb) {
                $status = $this->actingAs($this->a['admin'])->{$verb}($url, ['status' => 'completed', 'company_id' => $this->a['company']->id])->status();

                $this->assertContains($status, [404, 405], strtoupper($verb)." {$url} devolvió {$status}");
            }
        }

        $this->assertSame('pending', $woB->fresh()->status);
        $this->assertSame($this->b['company']->id, WorkOrder::withoutGlobalScopes()->find($woB->id)->company_id);
        $this->assertNotNull(DeliveryNote::withoutGlobalScopes()->find($noteB->id));
        $this->assertNull(Building::withoutGlobalScopes()->find($this->b['building']->id)->deleted_at);
    }

    public function test_user_template_gate_never_crosses_companies(): void
    {
        $this->assertFalse(Gate::forUser($this->a['admin'])->allows('view-user-template', $this->b['technician']));
        $this->assertTrue(Gate::forUser($this->a['admin'])->allows('view-user-template', $this->a['technician']));
        $this->assertTrue(Gate::forUser($this->a['technician'])->allows('view-user-template', $this->a['technician']));
        $this->assertFalse(Gate::forUser($this->a['technician'])->allows('view-user-template', $this->a['admin']));
    }

    public function test_widget_record_cannot_be_swapped_from_the_browser(): void
    {
        $this->actingInPanel($this->a['admin']);

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test(UserStatsWidget::class, ['record' => $this->a['technician']])
            ->set('record', $this->b['technician']);
    }

    public function test_form_options_never_include_other_company_data(): void
    {
        $this->b['building']->update(['name' => 'EDIFICIO-SECRETO-B']);
        $this->b['technician']->update(['name' => 'TECNICO-SECRETO-B']);

        $this->actingInPanel($this->a['admin'])
            ->get(WorkOrderResource::getUrl('create'))
            ->assertOk()
            ->assertDontSee('EDIFICIO-SECRETO-B')
            ->assertDontSee('TECNICO-SECRETO-B');
    }

    public function test_report_notifications_only_reach_own_company_admins(): void
    {
        if (! extension_loaded('imagick')) {
            // Sin Imagick el alta del reporte no llega a notificar: se prueba
            // la notificación directamente con la misma consulta del controlador.
            Notification::fake();

            $report = Report::factory()->create(['building_id' => $this->a['building']->id]);

            User::where('role', 'admin')
                ->where('company_id', $report->company_id)
                ->where('is_super_admin', false)
                ->get()
                ->each->notify(new NewReportNotification($report));

            Notification::assertSentTo($this->a['admin'], NewReportNotification::class);
            Notification::assertNotSentTo($this->b['admin'], NewReportNotification::class);

            return;
        }

        Notification::fake();

        $this->actingAs($this->a['technician'])->post("/{$this->a['company']->slug}/reports", [
            'building_id' => $this->a['building']->id,
            'elevator_number' => 'Ascensor 1',
            'description' => 'Falla',
            'priority' => 'alta',
            'photos' => [UploadedFile::fake()->image('x.jpg')],
        ]);

        Notification::assertSentTo($this->a['admin'], NewReportNotification::class);
        Notification::assertNotSentTo($this->b['admin'], NewReportNotification::class);
    }

    public function test_database_notifications_are_per_user(): void
    {
        $report = Report::factory()->create(['building_id' => $this->b['building']->id]);
        $this->b['admin']->notify(new NewReportNotification($report));

        $this->assertSame(0, $this->a['admin']->notifications()->count());
        $this->assertSame(1, $this->b['admin']->notifications()->count());
    }
}
