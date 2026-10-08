<?php

namespace App\Notifications;

use App\Filament\Resources\Reports\ReportResource;
use App\Models\Report;
use App\Notifications\Concerns\SendsAdminPush;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al admin: un técnico cargó un reporte (problema en un ascensor).
 */
class NewReportNotification extends Notification
{
    use SendsAdminPush;

    public function __construct(public Report $report) {}

    private function url(): string
    {
        return ReportResource::getUrl('view', ['record' => $this->report->id], panel: 'ascensores_app');
    }

    /** Los reportes usan prioridades propias: baja | media | alta | critica. */
    private function isCritical(): bool
    {
        return in_array($this->report->priority, ['critica', 'alta'], true);
    }

    private function priorityLabel(): ?string
    {
        return match ($this->report->priority) {
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'critica' => 'Crítica',
            default => null,
        };
    }

    private function title(): string
    {
        return $this->report->priority === 'critica' ? '🚨 Reporte crítico' : '⚠️ Nuevo reporte';
    }

    private function body(): string
    {
        $building = $this->report->building;

        return implode("\n", array_filter([
            $building ? trim("{$building->name} {$building->address}") : null,
            $this->report->user?->name ? 'Por '.$this->report->user->name : null,
            $this->priorityLabel() ? 'Prioridad: '.$this->priorityLabel() : null,
            $this->report->description ? Str::limit(Str::squish($this->report->description), 100) : null,
        ]));
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-exclamation-triangle')
            ->iconColor($this->isCritical() ? 'danger' : 'warning')
            ->actions([
                Action::make('view')->label('Ver reporte')->url($this->url())->markAsRead(),
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
            ->tag('report-'.$this->report->id)
            ->action('Ver reporte', 'open')
            ->data(['url' => parse_url($this->url(), PHP_URL_PATH)])
            ->requireInteraction($this->report->priority === 'critica')
            ->options(['TTL' => 24 * 3600, 'urgency' => $this->isCritical() ? 'high' : 'normal']);
    }

    /** @return array{text: string, button: array{0: string, 1: string}} */
    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => '<b>'.e($this->title()).'</b>'."\n".e($this->body()),
            'button' => ['Ver reporte', $this->url()],
        ];
    }
}
