<?php

namespace App\Filament\Pages;

use App\Http\Controllers\SubscriptionController;
use App\Models\SubscriptionPlan;
use App\Services\MercadoPagoService;
use Filament\Pages\Page;

class Subscription extends Page
{
    protected string $view = 'filament.pages.subscription';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationLabel = 'Mi suscripción';

    protected static ?string $title = 'Mi suscripción';

    protected static ?string $slug = 'subscription';

    public function mount(): void
    {
        abort_unless(
            auth()->user()?->isAdmin() || auth()->user()?->isSuperAdmin(),
            403
        );
    }

    public function getPlans()
    {
        return SubscriptionPlan::query()
            ->where('is_active', true)
            ->orderBy('price')
            ->get();
    }

    public function checkout(int|string $planId): void
    {
        $plan = SubscriptionPlan::query()
            ->whereKey((int) $planId)
            ->where('is_active', true)
            ->firstOrFail();

        $configKey = match ($plan->slug) {
            'basico', 'basic' => 'basic_plan_id',
            'profesional', 'pro' => 'pro_plan_id',
            default => throw new \RuntimeException(
                "El plan {$plan->name} no tiene un plan de Mercado Pago configurado."
            ),
        };

        $mercadoPagoPlanId = config("services.mercadopago.{$configKey}");

        if (empty($mercadoPagoPlanId)) {
            throw new \RuntimeException(
                "No está configurado el ID de Mercado Pago para el plan {$plan->name}."
            );
        }

        $mercadoPagoPlan = app(MercadoPagoService::class)
            ->getSubscriptionPlan((string) $mercadoPagoPlanId);

        $initPoint = $mercadoPagoPlan['init_point'] ?? null;

        if (empty($initPoint)) {
            throw new \RuntimeException(
                "Mercado Pago no devolvió init_point para el plan {$plan->name}."
            );
        }

        $this->redirect($initPoint, navigate: false);
    }

    public function changePlan(int|string $planId): void
{
    $plan = SubscriptionPlan::query()
        ->whereKey((int) $planId)
        ->where('is_active', true)
        ->firstOrFail();

    $this->checkout($plan->getKey());
}
}
