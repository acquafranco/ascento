<?php

namespace Tests\Feature\Notifications;

use App\Filament\Resources\WorkOrders\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrders\Pages\EditWorkOrder;
use App\Jobs\SendWorkOrderAssignedNotification;
use App\Models\User;
use App\Models\WorkOrder;
use App\Notifications\WorkOrderAssignedNotification;
use App\Notifications\WorkOrderUpdatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithTenants;
use Tests\Concerns\InteractsWithWebPush;
use Tests\TestCase;

/**
 * Push "Nueva orden de trabajo": a quién le llega, a quién nunca, y qué
 * pasa con reasignaciones, cancelaciones y dispositivos inválidos.
 */
class WorkOrderPushTest extends TestCase
{
    use InteractsWithTenants, InteractsWithWebPush, RefreshDatabase;

    private array $a;

    private array $b;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureVapid();
        $this->fakePushService();

        $this->a = $this->makeTenant();
        $this->b = $this->makeTenant();
    }

    private function technician(array $tenant): User
    {
        return User::factory()->technician()->create(['company_id' => $tenant['company']->id]);
    }

    private function createOrderInPanel(User $admin, array $technicianIds, array $overrides = []): WorkOrder
    {
        $this->actingInPanel($admin);

        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                'building_id' => $this->a['building']->id,
                'unit' => 'Ascensor 1',
                'users' => $technicianIds,
                'type' => 'claim',
                'priority' => 'high',
                'status' => 'pending',
                'notes' => 'Ascensor detenido en piso 3',
                ...$overrides,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        return WorkOrder::latest('id')->firstOrFail();
    }

    private function orderFor(array $tenant, array $technicians, array $attributes = []): WorkOrder
    {
        $workOrder = WorkOrder::factory()->create(['building_id' => $tenant['building']->id, ...$attributes]);
        $workOrder->users()->attach(collect($technicians)->pluck('id'));

        return $workOrder;
    }

    /*
    |--------------------------------------------------------------------------
    | QUIÉN LA RECIBE
    |--------------------------------------------------------------------------
    */

    public function test_assigned_technician_receives_the_push_when_the_order_is_created(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);

        $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($device);

        Http::assertSent(fn ($request) => $request->url() === $device->endpoint
            && $request->hasHeader('Authorization')                    // VAPID
            && $request->header('Content-Encoding')[0] === 'aes128gcm' // payload cifrado
            && $request->header('TTL')[0] === (string) (12 * 3600));
    }

    public function test_the_message_has_building_client_priority_and_opens_the_order(): void
    {
        $workOrder = $this->orderFor($this->a, [$this->a['technician']], ['priority' => 'urgent', 'notes' => 'Persona atrapada']);
        $workOrder->load('building.client', 'company');

        $message = (new WorkOrderAssignedNotification($workOrder))
            ->toWebPush($this->a['technician'], new WorkOrderAssignedNotification($workOrder))
            ->toArray();

        $building = $this->a['building'];

        $this->assertSame('🚨 Nueva orden de trabajo', $message['title']);
        $this->assertStringContainsString("Edificio: {$building->name} {$building->address}", $message['body']);
        $this->assertStringContainsString('Cliente: '.$building->client->name, $message['body']);
        $this->assertStringContainsString('Prioridad: Urgente', $message['body']);
        $this->assertStringContainsString('Persona atrapada', $message['body']);
        $this->assertSame([['title' => 'Abrir orden', 'action' => 'open']], $message['actions']);
        $this->assertSame("/{$this->a['company']->slug}/work-orders/{$workOrder->id}", $message['data']['url']);
        $this->assertSame('work-order-'.$workOrder->id, $message['tag']);
    }

    public function test_every_assigned_technician_and_every_device_is_notified(): void
    {
        $second = $this->technician($this->a);

        $phone = $this->subscribeDevice($this->a['technician']);
        $tablet = $this->subscribeDevice($this->a['technician'], 'web.push.apple.com');
        $secondPhone = $this->subscribeDevice($second);

        $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id, $second->id]);
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($phone);
        $this->assertPushDeliveredTo($tablet);
        $this->assertPushDeliveredTo($secondPhone);
    }

    public function test_unassigned_technicians_of_the_same_company_are_not_notified(): void
    {
        $assigned = $this->subscribeDevice($this->a['technician']);
        $colleague = $this->subscribeDevice($this->technician($this->a));

        $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($assigned);
        $this->assertNoPushDeliveredTo($colleague);
    }

    public function test_technicians_of_another_company_never_receive_it(): void
    {
        $other = $this->subscribeDevice($this->b['technician']);

        $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertNoPushDeliveredTo($other);
    }

    public function test_manipulated_ids_cannot_target_another_company(): void
    {
        $other = $this->subscribeDevice($this->b['technician']);
        $this->actingInPanel($this->a['admin']);

        // Livewire manipulado con el id de un técnico de la empresa B.
        Livewire::test(CreateWorkOrder::class)
            ->fillForm([
                'building_id' => $this->a['building']->id,
                'unit' => 'Ascensor 1',
                'users' => [$this->b['technician']->id],
                'type' => 'claim',
                'priority' => 'medium',
                'status' => 'pending',
            ])
            ->call('create')
            ->assertHasFormErrors(['users.0']);

        // Aun si la base quedara inconsistente (pivot cruzado), el envío lo frena.
        $workOrder = $this->orderFor($this->a, [$this->b['technician']]);
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->b['technician']->id);

        // Y un job con ids cruzados (orden de A, usuario de B no asignado) tampoco.
        SendWorkOrderAssignedNotification::dispatchSync($this->orderFor($this->a, [])->id, $this->b['technician']->id);

        $this->runAfterResponseJobs();
        $this->assertNoPushDeliveredTo($other);
    }

    public function test_users_without_permission_never_receive_it(): void
    {
        $adminDevice = $this->subscribeDevice($this->a['admin']);
        $superAdmin = User::factory()->superAdmin()->create();
        $superDevice = $this->subscribeDevice($superAdmin);

        $workOrder = $this->orderFor($this->a, [$this->a['admin'], $superAdmin]);

        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['admin']->id);
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $superAdmin->id);

        $this->assertNoPushDeliveredTo($adminDevice);
        $this->assertNoPushDeliveredTo($superDevice);

        // Técnico desactivado (soft delete).
        $device = $this->subscribeDevice($this->a['technician']);
        $this->a['technician']->delete();
        SendWorkOrderAssignedNotification::dispatchSync($this->orderFor($this->a, [$this->a['technician']])->id, $this->a['technician']->id);

        $this->assertNoPushDeliveredTo($device);
    }

    public function test_super_admin_creating_an_order_notifies_the_company_technician(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $superAdmin = User::factory()->superAdmin()->create();

        session(['selected_company_id' => $this->a['company']->id]);
        $this->createOrderInPanel($superAdmin, [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($device);
    }

    /*
    |--------------------------------------------------------------------------
    | REASIGNACIÓN / CANCELACIÓN
    |--------------------------------------------------------------------------
    */

    public function test_reassignment_notifies_only_the_new_technician(): void
    {
        $newTech = $this->technician($this->a);
        $oldDevice = $this->subscribeDevice($this->a['technician']);
        $newDevice = $this->subscribeDevice($newTech);

        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->fillForm(['users' => [$newTech->id]])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($newDevice);

        // El anterior solo recibe "ya no la tenés", sin datos de la orden.
        $this->assertRemovedNoticeOnly($this->a['technician'], $workOrder);
    }

    /** Un solo aviso de que se la quitaron, sin edificio ni detalle (ya no tiene acceso). */
    private function assertRemovedNoticeOnly($technician, $workOrder, string $title = 'Ya no tenés asignada una orden'): void
    {
        $notices = $technician->notifications()->get();
        $this->assertSame([$title], $notices->pluck('data.title')->all());
        $this->assertSame(0, $technician->notifications()->where('type', WorkOrderAssignedNotification::class)->count());
        $this->assertStringNotContainsString($this->a['building']->name, json_encode($notices->first()->data));
        $this->assertStringNotContainsString((string) $workOrder->notes ?: '§', json_encode($notices->first()->data));
        $this->assertNull($notices->first()->data['path']);
    }

    public function test_editing_an_order_notifies_the_technicians_already_assigned(): void
    {
        $second = $this->technician($this->a);
        $device = $this->subscribeDevice($this->a['technician']);
        $secondDevice = $this->subscribeDevice($second);
        $workOrder = $this->orderFor($this->a, [$this->a['technician'], $second]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->fillForm(['notes' => 'Cambio de detalle', 'priority' => 'urgent'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($device);
        $this->assertPushDeliveredTo($secondDevice);
    }

    public function test_the_edit_message_says_what_changed(): void
    {
        $workOrder = $this->orderFor($this->a, [$this->a['technician']], ['priority' => 'urgent']);
        $workOrder->load('building.client', 'company');

        $message = (new WorkOrderUpdatedNotification($workOrder, ['prioridad (Urgente)', 'detalle del trabajo']))
            ->toWebPush($this->a['technician'], new WorkOrderAssignedNotification($workOrder))
            ->toArray();

        $this->assertSame('✏️ Orden de trabajo modificada', $message['title']);
        $this->assertStringContainsString('Cambió: prioridad (Urgente), detalle del trabajo', $message['body']);
        $this->assertSame('work-order-'.$workOrder->id, $message['tag']); // reemplaza la anterior
        $this->assertSame("/{$this->a['company']->slug}/work-orders/{$workOrder->id}", $message['data']['url']);
    }

    public function test_saving_without_relevant_changes_does_not_notify(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->runAfterResponseJobs();

        $this->assertNoPushDeliveredTo($device);
    }

    public function test_removed_technicians_get_no_edit_notification_and_new_ones_get_new_order(): void
    {
        $newTech = $this->technician($this->a);
        $oldDevice = $this->subscribeDevice($this->a['technician']);
        $newDevice = $this->subscribeDevice($newTech);
        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->fillForm(['users' => [$newTech->id], 'notes' => 'Nuevo detalle'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->runAfterResponseJobs();

        $this->assertPushDeliveredTo($newDevice);
        $this->assertCount(2, $this->deliveredEndpoints()); // la orden nueva + el aviso de que se la quitaron
        $this->assertRemovedNoticeOnly($this->a['technician'], $workOrder);
    }

    public function test_closing_an_order_from_the_panel_does_not_send_an_edit_push(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $this->actingInPanel($this->a['admin']);
        Livewire::test(EditWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->fillForm(['status' => 'failed'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->runAfterResponseJobs();

        $this->assertNoPushDeliveredTo($device);
    }

    public function test_cancelled_or_closed_orders_are_not_notified(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $techId = $this->a['technician']->id;

        // Eliminada (cancelada) antes del envío.
        $deleted = $this->orderFor($this->a, [$this->a['technician']]);
        $deleted->delete();
        SendWorkOrderAssignedNotification::dispatchSync($deleted->id, $techId);

        // Cerradas.
        foreach (['completed', 'failed'] as $status) {
            SendWorkOrderAssignedNotification::dispatchSync($this->orderFor($this->a, [$this->a['technician']], ['status' => $status])->id, $techId);
        }

        // Nunca "Nueva orden"; la eliminada solo genera el aviso de cancelación.
        $this->assertRemovedNoticeOnly($this->a['technician'], $deleted, 'Orden de trabajo cancelada');
    }

    public function test_unassigned_before_sending_is_not_notified(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $workOrder->users()->detach($this->a['technician']->id);
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertNoPushDeliveredTo($device);
    }

    /*
    |--------------------------------------------------------------------------
    | ACCESO DE LA EMPRESA
    |--------------------------------------------------------------------------
    */

    public function test_company_without_access_does_not_send_pushes(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $workOrder = $this->orderFor($this->a, [$this->a['technician']]);

        $this->a['company']->forceFill(['trial_ends_at' => now()->subDay()])->save();
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->a['company']->forceFill(['trial_ends_at' => now()->addDay(), 'is_active' => false])->save();
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertNoPushDeliveredTo($device);
    }

    /*
    |--------------------------------------------------------------------------
    | DISPOSITIVOS INVÁLIDOS / SERVICIO CAÍDO
    |--------------------------------------------------------------------------
    */

    public function test_expired_subscriptions_are_deleted(): void
    {
        foreach ([410, 404] as $status) {
            $device = $this->subscribeDevice($this->a['technician']);
            $this->pushServiceResponds($status);

            SendWorkOrderAssignedNotification::dispatchSync($this->orderFor($this->a, [$this->a['technician']])->id, $this->a['technician']->id);

            $this->assertModelMissing($device);
        }
    }

    public function test_temporary_push_errors_keep_the_subscription_and_never_break_the_admin(): void
    {
        $device = $this->subscribeDevice($this->a['technician']);
        $this->pushServiceResponds(500);

        $workOrder = $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertModelExists($device);
        $this->assertModelExists($workOrder);

        $this->pushServiceResponds(fn () => throw new ConnectionException('timeout'));
        SendWorkOrderAssignedNotification::dispatchSync($workOrder->id, $this->a['technician']->id);

        $this->assertModelExists($device);
    }

    public function test_technician_without_devices_is_simply_skipped(): void
    {
        $this->createOrderInPanel($this->a['admin'], [$this->a['technician']->id]);
        $this->runAfterResponseJobs();

        $this->assertSame([], $this->deliveredEndpoints());
    }
}
