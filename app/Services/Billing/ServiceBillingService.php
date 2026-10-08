<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Genera las obligaciones periódicas de los servicios activos. Idempotente:
 * UNIQUE (servicio, período) en la base + verificación previa.
 */
class ServiceBillingService
{
    public function __construct(private ReceivableService $receivables) {}

    /**
     * Períodos que corresponde cobrar hasta $upTo (inclusive, nunca futuros).
     *
     * @return list<Carbon> primer día de cada período
     */
    public function periodsFor(MaintenanceService $service, ?CarbonInterface $upTo = null): array
    {
        $upTo = Carbon::parse($upTo ?? today())->startOfMonth();
        $cursor = Carbon::parse($service->start_date)->startOfMonth();
        $from = Carbon::parse($service->billing_from ?? $service->start_date)->startOfMonth();
        $end = $service->end_date ? Carbon::parse($service->end_date)->startOfMonth() : null;
        $step = $service->monthsPerPeriod();

        $periods = [];

        // Se respeta el "ritmo" de la frecuencia desde el inicio del servicio.
        while ($cursor->lte($upTo) && (! $end || $cursor->lte($end))) {
            if ($cursor->gte($from)) {
                $periods[] = $cursor->copy();
            }

            $cursor->addMonthsNoOverflow($step);
        }

        return $periods;
    }

    /** @return int obligaciones creadas */
    public function generate(MaintenanceService $service, ?CarbonInterface $upTo = null): int
    {
        if ($service->status !== MaintenanceService::ACTIVE || $service->trashed()) {
            return 0;
        }

        // Con un usuario autenticado, BelongsToCompany asigna su empresa a lo
        // que se crea: nunca generar cobros de un servicio de otra empresa.
        $context = CompanyContext::currentId();

        if ($context !== null && $context !== (int) $service->company_id) {
            return 0;
        }

        $client = Client::withoutGlobalScopes()->withTrashed()->find($service->client_id);

        if (! $client || (int) $client->company_id !== (int) $service->company_id) {
            return 0;
        }

        $created = 0;

        foreach ($this->periodsFor($service, $upTo) as $period) {
            $exists = Receivable::withoutGlobalScopes()
                ->where('maintenance_service_id', $service->id)
                ->whereDate('period_start', $period)
                ->exists();

            if ($exists) {
                continue;
            }

            try {
                DB::transaction(fn () => $this->receivables->create(
                    $client,
                    $service->building_id,
                    'service',
                    $service->description.' — '.ucfirst($period->locale('es')->translatedFormat('F Y')),
                    (float) $service->amount,
                    $period->copy()->day($service->payment_due_day),
                    auth()->user(),
                    serviceId: $service->id,
                    periodStart: $period,
                ));
                $created++;
            } catch (UniqueConstraintViolationException) {
                // Otro proceso lo generó al mismo tiempo: ya existe.
            }
        }

        return $created;
    }

    /** @return int obligaciones creadas */
    public function generateAll(?int $companyId = null, ?CarbonInterface $upTo = null): int
    {
        return MaintenanceService::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('status', MaintenanceService::ACTIVE)
            ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
            ->get()
            ->sum(fn (MaintenanceService $service) => $this->generate($service, $upTo));
    }
}
