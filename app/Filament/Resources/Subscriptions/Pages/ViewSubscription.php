<?php

namespace App\Filament\Resources\Subscriptions\Pages;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSubscriptionSync;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionResource::class;

    public function getTitle(): string
    {
        return 'Suscripción de '.($this->record->company?->name ?? 'empresa');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sincronizar con Mercado Pago')
                ->icon('heroicon-m-arrow-path')
                ->color('gray')
                ->visible(fn () => $this->record->isMercadoPago() && $this->record->provider_subscription_id && MercadoPagoService::isConfigured())
                ->action(function () {
                    try {
                        app(MercadoPagoSubscriptionSync::class)->reconcile($this->record);
                        $this->record->refresh();
                        Notification::make()->title('Sincronizada con Mercado Pago')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()->title('No se pudo sincronizar')->body($e->getMessage())->danger()->send();
                    }
                }),

            Action::make('enter')
                ->label('Entrar a la empresa')
                ->icon('heroicon-o-arrow-right')
                ->action(function () {
                    session(['selected_company_id' => $this->record->company_id]);

                    return redirect()->to('/admin');
                }),
        ];
    }
}
