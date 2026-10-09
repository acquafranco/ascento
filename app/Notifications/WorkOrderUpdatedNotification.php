<?php

namespace App\Notifications;

use App\Models\WorkOrder;
use App\Notifications\Channels\TelegramChannel;
use Filament\Actions\Action;
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
        return array_values(array_filter([
            'database', // bandeja del técnico: queda aunque el push falle
            WebPushChannel::class,
            TelegramChannel::enabledFor($notifiable) ? TelegramChannel::class : null,
        ]));
    }

    public function toDatabase(object $notifiable): array
    {
        $path = route('work-orders.show', ['company' => $this->workOrder->company->slug, 'workOrder' => $this->workOrder->id], absolute: false);

        return \Filament\Notifications\Notification::make()
            ->title('✏️ Orden de trabajo modificada')
            ->body(trim(($this->workOrder->building?->name ?? '').' '.($this->workOrder->building?->address ?? '')).($this->changes ? ' · Cambió: '.implode(', ', $this->changes) : ''))
            ->icon('heroicon-o-wrench-screwdriver')
            ->actions([Action::make('view')->label('Ver orden')->url(url($path))->markAsRead()])
            ->getDatabaseMessage() + ['path' => $path];
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

    /** @return array{text: string, button: array{0: string, 1: string}} */
    public function toTelegram(object $notifiable): array
    {
        $building = $this->workOrder->building;

        $lines = array_filter([
            '<b>✏️ Orden de trabajo modificada</b>',
            $building ? '🏢 '.e(trim("{$building->name} {$building->address}")) : null,
            $this->changes ? 'Cambió: '.e(implode(', ', $this->changes)) : null,
        ]);

        return [
            'text' => implode("\n", $lines),
            'button' => ['Ver orden', route('work-orders.show', ['company' => $this->workOrder->company->slug, 'workOrder' => $this->workOrder->id])],
        ];
    }
}
