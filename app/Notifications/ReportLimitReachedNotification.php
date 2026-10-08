<?php

namespace App\Notifications;

use App\Enums\PlanLimit;
use App\Models\Company;
use App\Notifications\Concerns\SendsAdminPush;
use App\Support\Plans\PlanGuard;
use App\Support\Plans\PlanUpsell;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Aviso al admin (una vez por mes): sus técnicos ya no pueden cargar
 * reportes porque se alcanzó el cupo mensual del plan.
 */
class ReportLimitReachedNotification extends Notification
{
    use SendsAdminPush;

    public function __construct(public Company $company) {}

    private function title(): string
    {
        return PlanGuard::for($this->company)->limitReachedMessage(PlanLimit::ReportsPerMonth);
    }

    private function body(): string
    {
        return 'Tus técnicos no pueden cargar más reportes hasta el mes que viene. '
            .(PlanGuard::for($this->company)->upgradePitch(PlanLimit::ReportsPerMonth) ?? '');
    }

    public function toDatabase(object $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-arrow-trending-up')
            ->iconColor('warning')
            ->actions([
                Action::make('plans')->label('Ver planes')->url(PlanUpsell::url(PlanLimit::ReportsPerMonth))->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('📋 '.$this->title())
            ->body($this->body())
            ->icon('/images/pwa/icon-192.png')
            ->badge('/images/pwa/badge-96.png')
            ->tag('report-limit')
            ->data(['url' => parse_url(PlanUpsell::url(PlanLimit::ReportsPerMonth), PHP_URL_PATH)])
            ->options(['TTL' => 24 * 3600]);
    }

    public function toTelegram(object $notifiable): array
    {
        return [
            'text' => '<b>📋 '.e($this->title()).'</b>'."\n".e($this->body()),
            'button' => ['Ver planes', PlanUpsell::url(PlanLimit::ReportsPerMonth)],
        ];
    }
}
