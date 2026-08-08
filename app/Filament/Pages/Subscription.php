<?php

namespace App\Filament\Pages;

use App\Http\Controllers\SubscriptionController;
use App\Models\SubscriptionPlan;
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

        $response = app(SubscriptionController::class)->checkout(
            request(),
            (string) $plan->getKey()
        );

        if ($response instanceof \Symfony\Component\HttpFoundation\RedirectResponse) {
            $this->redirect($response->getTargetUrl(), navigate: false);
            return;
        }

        if (is_string($response)) {
            $this->redirect($response, navigate: false);
            return;
        }

        throw new \RuntimeException('Mercado Pago no devolvió una URL de checkout válida.');
    }
}
