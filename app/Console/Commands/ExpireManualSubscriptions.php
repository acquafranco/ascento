<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use Illuminate\Console\Command;

class ExpireManualSubscriptions extends Command
{
    protected $signature = 'subscription:expire-manual';

    protected $description = 'Congela (pausa) las suscripciones manuales cuyo período pagado ya venció sin renovar.';

    public function handle(): int
    {
        $expired = Subscription::where('provider', 'manual')
            ->where('status', 'active')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<', now())
            ->get();

        foreach ($expired as $subscription) {
            $subscription->update([
                'status' => 'paused',
                'cancel_at_period_end' => false,
            ]);

            $this->info("Empresa #{$subscription->company_id}: congelada por falta de pago.");
        }

        $this->info('Total procesadas: ' . $expired->count() . '.');

        return self::SUCCESS;
    }
}
