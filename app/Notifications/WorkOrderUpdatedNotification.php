<?php

namespace App\Notifications;

use App\Models\WorkOrder;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push al técnico cuando el admin modifica una orden que ya tenía asignada.
 * Usa el mismo "tag" que el aviso de orden nueva: reemplaza al anterior en
 * el celular en vez de acumular notificaciones de la misma orden.
 */
class WorkOrderUpdatedNotification extends Notification
{
    /** @param  list<string>  $changes  Qué cambió, en palabras ("prioridad: Urgente"). */
    public function __construct(public WorkOrder $workOrder, public array $changes) {}

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $workOrder = $this->workOrder;
        $building = $workOrder->building;

        $lines = array_filter([
            $building ? 'Edificio: '.trim("{$building->name} {$building->address}") : null,
            $this->changes ? 'Cambió: '.implode(', ', $this->changes) : null,
        ]);

        return (new WebPushMessage)
            ->title('✏️ Orden de trabajo modificada')
            ->body(implode("\n", $lines))
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag('work-order-'.$workOrder->id)
            ->renotify()
            ->action('Ver orden', 'open')
            ->data([
                'url' => route('work-orders.show', [
                    'company' => $workOrder->company->slug,
                    'workOrder' => $workOrder->id,
                ], absolute: false),
                'workOrderId' => $workOrder->id,
            ])
            ->options([
                'TTL' => 12 * 3600,
                'urgency' => $workOrder->priority === 'urgent' ? 'high' : 'normal',
            ]);
    }
}
