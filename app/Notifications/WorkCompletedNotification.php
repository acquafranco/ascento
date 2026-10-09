<?php

namespace App\Notifications;

use App\Filament\Resources\DeliveryNotes\DeliveryNoteResource;
use App\Models\DeliveryNote;
use App\Notifications\Concerns\SendsAdminPush;
use App\Support\WorkOrderLabels;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al admin: un técnico terminó un trabajo (firmó el remito de un
 * mantenimiento, una inspección o una orden de trabajo).
 */
class WorkCompletedNotification extends Notification
{
    use SendsAdminPush;

    public function __construct(public DeliveryNote $deliveryNote) {}

    private function url(): string
    {
        return DeliveryNoteResource::getUrl('view', ['record' => $this->deliveryNote->id], panel: 'ascensores_app');
    }

    /** Remito firmado como "no realizado": requiere seguimiento del admin. */
    private function notDone(): bool
    {
        return $this->deliveryNote->performed === false;
    }

    private function title(): string
    {
        return $this->notDone() ? '⚠️ Trabajo NO realizado' : '✅ Trabajo terminado';
    }

    private function kind(): string
    {
        return match ($this->deliveryNote->assignment_type) {
            'maintenance' => 'Mantenimiento',
            'inspection' => 'Inspección',
            'work_order' => $this->deliveryNote->workOrder
                ? 'Orden de trabajo ('.WorkOrderLabels::type((string) $this->deliveryNote->workOrder->type).')'
                : 'Orden de trabajo',
            default => 'Trabajo',
        };
    }

    private function body(): string
    {
        $building = $this->deliveryNote->building;

        return implode("\n", array_filter([
            $this->kind().($building ? ' · '.trim("{$building->name} {$building->address}") : ''),
            $this->deliveryNote->user?->name ? 'Por '.$this->deliveryNote->user->name : null,
            $this->deliveryNote->performed === false ? 'Marcado como NO realizado' : null,
        ]));
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon($this->notDone() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle')
            ->iconColor($this->notDone() ? 'danger' : 'success')
            ->actions([
                Action::make('view')->label('Ver remito')->url($this->url())->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title())
            ->body($this->body())
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag('delivery-note-'.$this->deliveryNote->id)
            ->action('Ver remito', 'open')
            ->data(['url' => parse_url($this->url(), PHP_URL_PATH)])
            ->options(['TTL' => 24 * 3600]);
    }

    /** @return array{text: string, button: array{0: string, 1: string}} */
    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => '<b>'.e($this->title()).'</b>'."\n".e($this->body()),
            'button' => ['Ver remito', $this->url()],
        ];
    }
}
