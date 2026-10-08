<?php

namespace Tests\Feature\Notifications;

use App\Filament\Pages\BuildingsMap;
use App\Filament\Pages\CompanySettings;
use App\Filament\Pages\Dashboard;
use App\Jobs\NotifyCompanyAdmins;
use App\Models\Report;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\NewReportNotification;
use App\Notifications\WorkCompletedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Avisos al administrador (campanita del panel + push): trabajo terminado
 * y reporte nuevo. Solo a los admins de ESA empresa.
 */
class AdminNotificationsTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    private array $b;

    private User $super;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        $this->configureVapid();
        $this->fakePushService();

        $this->super = User::factory()->superAdmin()->create();
        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    /** Reporte cargado por el técnico (con la misma notificación que dispara el controller). */
    private function createReport(array $tenant, string $priority = 'alta'): void
    {
        $report = Report::factory()->create([
            'company_id' => $tenant['company']->id,
            'building_id' => $tenant['building']->id,
            'user_id' => $tenant['technician']->id,
            'priority' => $priority,
            'description' => 'Ruido fuerte en la cabina',
        ]);

        NotifyCompanyAdmins::dispatchSync(NotifyCompanyAdmins::REPORT, $report->id);
    }

    private function createReportThroughTheApp(array $tenant, string $priority = 'alta'): void
    {
        $this->actingAs($tenant['technician'])
            ->post("/{$tenant['company']->slug}/reports", [
                'building_id' => $tenant['building']->id,
                'elevator_number' => 'Ascensor 1',
                'description' => 'Ruido fuerte en la cabina',
                'priority' => $priority,
                'photo' => UploadedFile::fake()->image('foto.jpg'),
            ])
            ->assertSessionHasNoErrors();
    }

    private function signWorkOrder(array $tenant): void
    {
        $workOrder = WorkOrder::factory()->inProgress()->create(['building_id' => $tenant['building']->id]);
        $workOrder->users()->attach($tenant['technician']->id);
        $workOrder->participants()->attach($tenant['technician']->id, ['role' => 'participant']);

        $this->actingAs($tenant['technician'])
            ->post("/{$tenant['company']->slug}/delivery-notes/store", [
                'building_id' => $tenant['building']->id,
                'work_order_id' => $workOrder->id,
                'assignment_type' => 'work_order',
                'description' => 'Se cambió el contactor',
                'month' => now()->month,
                'year' => now()->year,
                'elevator_quantity' => 1,
                'freight_elevator_quantity' => 0,
                'signature_name' => 'Técnico',
                'signature' => $this->validSignature(),
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_new_report_reaches_the_company_admins_bell_and_phone(): void
    {
        $device = $this->subscribeDevice($this->a['admin']);
        $otherAdminDevice = $this->subscribeDevice($this->b['admin']);

        $this->createReport($this->a, 'critica');

        $notification = $this->a['admin']->notifications()->sole();
        $this->assertSame(NewReportNotification::class, $notification->type);
        $this->assertSame('🚨 Reporte crítico', $notification->data['title']);
        $this->assertStringContainsString('Prioridad: Crítica', $notification->data['body']);
        $this->assertSame('Ver reporte', $notification->data['actions'][0]['label']);
        $this->assertStringContainsString('/admin/reports/'.Report::withoutGlobalScopes()->sole()->id, $notification->data['actions'][0]['url']);

        $this->assertPushDeliveredTo($device);
        $this->assertNoPushDeliveredTo($otherAdminDevice);

        $this->assertSame(0, $this->b['admin']->notifications()->count());
        $this->assertSame(0, $this->super->notifications()->count());
        $this->assertSame(0, $this->a['technician']->notifications()->count());
    }

    public function test_the_technician_report_form_triggers_the_alert(): void
    {
        if (! extension_loaded('imagick') && ! extension_loaded('gd')) {
            $this->markTestSkipped('Requiere Imagick o GD (procesa la foto antes de guardar).');
        }

        $this->createReportThroughTheApp($this->a, 'critica');

        $this->assertSame(1, $this->a['admin']->notifications()->count());
        $this->assertSame(0, $this->b['admin']->notifications()->count());
    }

    public function test_signed_work_reaches_the_admins_as_work_completed(): void
    {
        $device = $this->subscribeDevice($this->a['admin']);

        $this->signWorkOrder($this->a);

        $notification = $this->a['admin']->notifications()->sole();
        $this->assertSame(WorkCompletedNotification::class, $notification->type);
        $this->assertSame('✅ Trabajo terminado', $notification->data['title']);
        $this->assertStringContainsString('Orden de trabajo', $notification->data['body']);
        $this->assertStringContainsString('Por '.$this->a['technician']->name, $notification->data['body']);
        $this->assertSame('Ver remito', $notification->data['actions'][0]['label']);

        $this->assertPushDeliveredTo($device);
        $this->assertSame(0, $this->b['admin']->notifications()->count());
    }

    public function test_companies_without_access_do_not_notify(): void
    {
        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();

        // El técnico ni siquiera puede operar; y si un aviso quedara en cola, se descarta.
        NotifyCompanyAdmins::dispatchSync(NotifyCompanyAdmins::REPORT, Report::factory()->create([
            'company_id' => $this->a['company']->id,
            'building_id' => $this->a['building']->id,
            'user_id' => $this->a['technician']->id,
        ])->id);

        $this->assertSame(0, $this->a['admin']->notifications()->count());
    }

    public function test_without_vapid_keys_the_bell_still_works(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);

        $this->createReport($this->a);

        $this->assertSame(1, $this->a['admin']->notifications()->count());
        $this->assertSame([], $this->deliveredEndpoints());
    }

    public function test_admin_sees_how_to_enable_alerts_in_the_panel(): void
    {
        foreach ([Dashboard::getUrl(), BuildingsMap::getUrl()] as $url) {
            $this->actingInPanel($this->a['admin'])
                ->get($url)
                ->assertOk()
                ->assertSee('name="ascento-push"', false)
                ->assertSee('Recibí avisos en este dispositivo')
                ->assertSee('Ahora no')
                ->assertDontSee(config('webpush.vapid.private_key'));
        }

        $this->actingInPanel($this->a['admin'])
            ->get(CompanySettings::getUrl())
            ->assertOk()
            ->assertSee('Enviar prueba');
    }

    public function test_super_admin_does_not_get_the_alerts_banner(): void
    {
        session(['selected_company_id' => $this->a['company']->id]);

        $this->actingInPanel($this->super)
            ->get(Dashboard::getUrl())
            ->assertOk()
            ->assertDontSee('Recibí avisos en este dispositivo');
    }
}
