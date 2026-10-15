<?php

namespace App\Services\Billing;

use App\Models\Client;
use App\Models\MaintenanceService;
use App\Models\Receivable;
use App\Models\User;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

        // Pago único: un solo cobro, el del primer mes que corresponde cobrar (nunca antes de cargarlo).
        if ($service->frequency === 'once') {
            return $from->lte($upTo) && (! $end || $from->lte($end)) ? [$from->copy()] : [];
        }

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

        // Pago único: un solo cobro en toda su vida, aunque se pause, se reactive o cambien sus fechas.
        if ($service->frequency === 'once' && Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id)->exists()) {
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

    /**
     * ¿Se puede volver a cobrar este pago único? Solo si ya tuvo cobro, todos sus
     * cobros están anulados (ninguno pendiente, vencido, parcial o pagado) y la
     * contratación no está pausada.
     */
    public function canRebill(MaintenanceService $service): bool
    {
        if ($service->frequency !== 'once' || $service->status === MaintenanceService::PAUSED || $service->trashed()) {
            return false;
        }

        $receivables = Receivable::withoutGlobalScopes()->where('maintenance_service_id', $service->id);

        return (clone $receivables)->exists() && ! (clone $receivables)->where('status', '!=', Receivable::VOID)->exists();
    }

    /** Vencimiento del nuevo cobro: el día pactado de este mes o, si ya pasó, del siguiente. */
    public function rebillDueDate(MaintenanceService $service): Carbon
    {
        $day = max(1, min(28, (int) $service->payment_due_day));
        $due = today()->day($day);

        return $due->lt(today()) ? today()->startOfMonth()->addMonthNoOverflow()->day($day) : $due;
    }

    /**
     * «Volver a cobrar» un pago único cuyo cobro se anuló: un cobro nuevo vinculado
     * a la contratación, por su importe acordado. El anulado queda como está. Con
     * bloqueo sobre la contratación y la verificación dentro de la transacción: un
     * doble clic o dos solicitudes simultáneas no crean dos cobros.
     */
    public function rebillOneTime(MaintenanceService $service, ?User $user): Receivable
    {
        return DB::transaction(function () use ($service, $user) {
            $locked = MaintenanceService::withoutGlobalScopes()->lockForUpdate()->findOrFail($service->id);

            $context = CompanyContext::currentId();
            if ($context !== null && $context !== (int) $locked->company_id) {
                throw ValidationException::withMessages(['rebill' => 'Esa contratación no es de tu empresa.']);
            }

            if (! $this->canRebill($locked)) {
                throw ValidationException::withMessages(['rebill' => 'Solo se puede volver a cobrar un pago único cuyo cobro está anulado y que no tiene otro cobro vigente.']);
            }

            $client = Client::withoutGlobalScopes()->withTrashed()->find($locked->client_id);
            if (! $client || (int) $client->company_id !== (int) $locked->company_id) {
                throw ValidationException::withMessages(['rebill' => 'La contratación no tiene un cliente válido.']);
            }

            return $this->receivables->create(
                $client,
                $locked->building_id,
                'service',
                $locked->description.' — nuevo cobro (reemplaza el anulado)',
                (float) $locked->amount,
                $this->rebillDueDate($locked),
                $user,
                serviceId: $locked->id,
            );
        });
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
