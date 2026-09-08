<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Support\ManualSubscriptionActivator;
use Illuminate\Console\Command;

class ActivateManualSubscription extends Command
{
    protected $signature = 'subscription:activate {company_id} {--days=30}';

    protected $description = 'Activa o extiende manualmente el acceso de una empresa que pagó por transferencia.';

    public function handle(): int
    {
        $company = Company::find($this->argument('company_id'));

        if (!$company) {
            $this->error('No existe una empresa con ese ID.');

            return self::FAILURE;
        }

        $subscription = ManualSubscriptionActivator::activate($company, (int) $this->option('days'));

        $this->info(
            "Empresa #{$company->id} ({$company->name}) activa hasta "
            . $subscription->current_period_end->format('d/m/Y') . '.'
        );

        return self::SUCCESS;
    }
}
