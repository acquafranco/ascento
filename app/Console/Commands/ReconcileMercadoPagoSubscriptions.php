<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Services\MercadoPagoService;
use App\Services\MercadoPagoSubscriptionSync;
use Illuminate\Console\Command;
use Throwable;

class ReconcileMercadoPagoSubscriptions extends Command
{
    protected $signature = 'subscriptions:reconcile-mercadopago {--company= : Solo esta empresa (id)}';

    protected $description = 'Vuelve a leer de Mercado Pago el estado y los cobros de cada suscripción (por si se perdió un webhook).';

    public function handle(MercadoPagoSubscriptionSync $sync): int
    {
        if (! MercadoPagoService::isConfigured()) {
            $this->warn('MERCADOPAGO_ACCESS_TOKEN no está configurado.');

            return self::SUCCESS;
        }

        $subscriptions = Subscription::where('provider', 'mercadopago')
            ->whereNotNull('provider_subscription_id')
            ->whereIn('status', [Subscription::PENDING, Subscription::AUTHORIZED, Subscription::PAST_DUE, Subscription::PAUSED])
            ->when($this->option('company'), fn ($q, $id) => $q->where('company_id', (int) $id))
            ->get();

        foreach ($subscriptions as $subscription) {
            try {
                $sync->reconcile($subscription);
                $this->line("Empresa #{$subscription->company_id}: {$subscription->fresh()->status}");
            } catch (Throwable $e) {
                $this->error("Empresa #{$subscription->company_id}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
