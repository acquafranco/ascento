<?php

namespace App\Notifications;

use App\Models\WorkOrder;
use App\Notifications\Channels\TelegramChannel;
use App\Support\WorkOrderLabels;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Push al técnico cuando le asignan una orden de trabajo.
 *
 * No se envía directo: lo despacha SendWorkOrderAssignedNotification, que
 * revalida empresa, asignación, estado de la orden y acceso de la empresa
 * justo antes de mandarla.
 */
class WorkOrderAssignedNotification extends Notification
{
    public function __construct(public WorkOrder $workOrder) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter([
            WebPushChannel::class,
            TelegramChannel::enabledFor($notifiable) ? TelegramChannel::class : null,
        ]));
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $workOrder = $this->workOrder;
        $building = $workOrder->building;

        $lines = array_filter([
            $building ? 'Edificio: '.trim("{$building->name} {$building->address}") : null,
            $building?->client?->name ? 'Cliente: '.$building->client->name : null,
            'Prioridad: '.WorkOrderLabels::priority((string) $workOrder->priority)
                .($workOrder->unit ? ' · '.$workOrder->unit : ''),
            $workOrder->notes ? Str::limit(Str::squish($workOrder->notes), 120) : null,
        ]);

        $urgent = in_array($workOrder->priority, ['urgent', 'high'], true);

        return (new WebPushMessage)
            ->title(($workOrder->priority === 'urgent' ? '🚨 ' : '🔧 ').'Nueva orden de trabajo')
            ->body(implode("\n", $lines))
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            // Una sola notificación por orden aunque llegue dos veces.
            ->tag('work-order-'.$workOrder->id)
            ->renotify()
            ->requireInteraction($urgent)
            ->action('Abrir orden', 'open')
            ->data([
                'url' => route('work-orders.show', [
                    'company' => $workOrder->company->slug,
                    'workOrder' => $workOrder->id,
                ], absolute: false),
                'workOrderId' => $workOrder->id,
            ])
            ->options([
                // Si el celular está apagado, se entrega hasta 12 h después.
                'TTL' => 12 * 3600,
                'urgency' => $urgent ? 'high' : 'normal',
            ]);
    }

    /** @return array{text: string, button: array{0: string, 1: string}} */
    public function toTelegram(object $notifiable): array
    {
        $workOrder = $this->workOrder;
        $building = $workOrder->building;

        $lines = array_filter([
            '<b>'.($workOrder->priority === 'urgent' ? '🚨' : '🔧').' Nueva orden de trabajo</b>',
            $building ? '🏢 '.e(trim("{$building->name} {$building->address}")) : null,
            $building?->client?->name ? '👤 '.e($building->client->name) : null,
            '⚡ Prioridad: '.e(WorkOrderLabels::priority((string) $workOrder->priority)).($workOrder->unit ? ' · '.e($workOrder->unit) : ''),
            $workOrder->notes ? "\n".e(Str::limit(Str::squish($workOrder->notes), 300)) : null,
        ]);

        return [
            'text' => implode("\n", $lines),
            'button' => ['Abrir orden', route('work-orders.show', ['company' => $this->workOrder->company->slug, 'workOrder' => $this->workOrder->id])],
        ];
    }
}
